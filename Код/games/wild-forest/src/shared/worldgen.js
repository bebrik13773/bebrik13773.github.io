// Генерация мира (ДЛ-05): рельеф, биомы, деревья, булыжники, руда, город, зоны.
// Зеркало на сервере: api/forest/lib/worldgen.php. Любое изменение алгоритма или чисел в biomes.json
// требует пересоздать тест-векторы (node tests/gen-vectors.mjs) и проверить selftest на хостинге.
//
// Единицы ядра: ЦЕЛЫЕ ДЕЦИМЕТРЫ (X, Z). Обёртки в метрах (heightAt, biomeAt) только для удобства клиента.
// Клетка 4x4 м = 40x40 дм, чанк 32x32 м = 8x8 клеток. id дерева: "cx:cz:ci", ci = lz*8 + lx.
import { hash, fbm, floorDiv, isqrt } from './rng.js';

export const BIOME = Object.freeze({ WATER: 0, ROCKS: 1, SWAMP: 2, CONIFER: 3, BROADLEAF: 4, BIRCH: 5 });
export const KIND = Object.freeze({ NONE: 0, TREE: 1, BOULDER: 2, ORE: 3 });
export const SIZE = Object.freeze({ SMALL: 0, MEDIUM: 1, LARGE: 2 });
export const ZONE = Object.freeze({ EDGE: 0, THICKET: 1, WILD: 2 });

export const CELL_DM = 40;
export const CELLS_PER_CHUNK = 8;
export const CHUNK_M = 32;

/** Создаёт генератор мира по сиду и общим данным (species.json, biomes.json). */
export function createWorldGen({ seed, species, biomes }) {
    seed = seed >>> 0;
    const P = biomes.params;
    const SP = species.species;
    const speciesIndex = new Map(SP.map((s, i) => [s.id, i]));
    const SALT = P.salts;
    const PER = P.periods_dm;
    const W = P.water;
    const BM = P.biome;
    const CL = P.cell;
    const WD = P.world;

    // Таблицы биомов по коду: плотность, веса пород, редкая порода.
    const byCode = [];
    for (const b of biomes.biomes) {
        const list = b.species.map(([id, w]) => [speciesIndex.get(id), w]);
        const total = list.reduce((s, [, w]) => s + w, 0);
        byCode[b.code] = {
            density: b.density_permille,
            list,
            total,
            rare: b.rare ? speciesIndex.get(b.rare) : -1,
        };
    }

    // ── Поля шума (0..65535) ────────────────────────────────────────────
    const fieldE = (X, Z) => fbm(seed, X, Z, PER.E, SALT.E);
    const fieldM = (X, Z) => fbm(seed, X, Z, PER.M, SALT.M);
    const fieldC = (X, Z) => fbm(seed, X, Z, PER.C, SALT.C);
    const fieldD = (X, Z) => fbm(seed, X, Z, PER.D, SALT.D);
    /** Русло: расстояние до «складки» 32768, чем меньше, тем ближе к реке. */
    const fieldR = (X, Z) => Math.abs(fbm(seed, X, Z, PER.R, SALT.R) - 32768);

    const isLakeWater = (E) => E < W.lake_e;
    /** Эффективное расстояние до русла: на высоких местах растёт, и река плавно сходит на нет (без обрывов). */
    const riverDist = (E, R) => R + Math.max(0, E - W.river_fade_e);
    const isRiverWater = (E, R) => riverDist(E, R) < W.river_r;

    /** Линейная смесь a и b с весом num/den; значения могут быть отрицательными (сдвиг 2000 дм). */
    const lerpN = (a, b, num, den) => Math.floor(((a + 2000) * (den - num) + (b + 2000) * num) / den) - 2000;

    function heightFromFields(E, R, D) {
        let H = Math.floor((E * P.height.e_scale) / 65535) + P.height.base + Math.floor((D * P.height.d_scale) / 65535);
        const lake = isLakeWater(E);
        const Re = riverDist(E, R);
        const river = Re < W.river_r;
        if (lake || river) {
            let bed = 1e9;
            if (lake) bed = Math.min(bed, W.level - W.bed_gap - Math.floor(((W.lake_e - E) * W.lake_depth) / W.lake_e));
            if (river) bed = Math.min(bed, W.level - W.bed_gap - Math.floor(((W.river_r - Re) * W.river_depth) / W.river_r));
            return bed;
        }
        if (E < W.lake_ramp_e) {
            H = lerpN(H, W.level + W.bank_gap, W.lake_ramp_e - E, W.lake_ramp_e - W.lake_e);
        }
        if (Re < W.river_ramp_r) {
            H = lerpN(H, W.level + W.bank_gap, W.river_ramp_r - Re, W.river_ramp_r - W.river_r);
        }
        return H;
    }

    function biomeFromFields(E, M, C, R) {
        if (isLakeWater(E) || isRiverWater(E, R)) return BIOME.WATER;
        if (E > BM.rocks_e) return BIOME.ROCKS;
        if (M > BM.swamp_m && E < BM.swamp_e_max) return BIOME.SWAMP;
        if (C >= BM.conifer_c) return BIOME.CONIFER;
        if (C < BM.grove_c) return BIOME.BIRCH;
        return BIOME.BROADLEAF;
    }

    /** Высота рельефа в дециметрах. */
    function heightAtDm(X, Z) {
        return heightFromFields(fieldE(X, Z), fieldR(X, Z), fieldD(X, Z));
    }

    /** Код биома в точке (дм). */
    function biomeAtDm(X, Z) {
        return biomeFromFields(fieldE(X, Z), fieldM(X, Z), fieldC(X, Z), fieldR(X, Z));
    }

    const isInsideWorld = (X, Z) => Math.abs(X) <= WD.radius_dm && Math.abs(Z) <= WD.radius_dm;
    /** Город в точке (0, 0): круг радиусом 120 м без деревьев. */
    const isCityZone = (X, Z) => X * X + Z * Z < WD.city_radius_dm * WD.city_radius_dm;
    const distDm = (X, Z) => isqrt(X * X + Z * Z);
    const tierAtDm = (X, Z) => Math.min(WD.tier_max, Math.floor(distDm(X, Z) / WD.tier_step_dm));
    function zoneAtDm(X, Z) {
        const d = distDm(X, Z);
        if (d < WD.zone_edge_dm) return ZONE.EDGE;
        if (d < WD.zone_deep_dm) return ZONE.THICKET;
        return ZONE.WILD;
    }

    /** Шанс редкой породы (на 10 000) для ступени удалённости. */
    const rareChance = (tier) => Math.min(CL.rare_cap_per10k, Math.floor((CL.rare_base_per10k * (100 + CL.rare_tier_pct * tier)) / 100));

    /**
     * Содержимое клетки (cx, cz, ci). Возвращает компактный объект.
     * kind: 0 пусто, 1 дерево, 2 булыжник, 3 руда. ox, oz: смещение внутри клетки (дм).
     */
    function cellAt(cx, cz, ci) {
        const gx = cx * CELLS_PER_CHUNK + (ci % CELLS_PER_CHUNK);
        const gz = cz * CELLS_PER_CHUNK + Math.floor(ci / CELLS_PER_CHUNK);
        const X = gx * CELL_DM + CELL_DM / 2;
        const Z = gz * CELL_DM + CELL_DM / 2;
        const empty = { kind: KIND.NONE, ox: 0, oz: 0, species: -1, size: -1, rare: 0, treasure: 0, biome: BIOME.WATER, X, Z };
        if (!isInsideWorld(X, Z)) return empty;
        const E = fieldE(X, Z);
        const biome = biomeFromFields(E, fieldM(X, Z), fieldC(X, Z), fieldR(X, Z));
        empty.biome = biome;
        if (biome === BIOME.WATER || isCityZone(X, Z)) return empty;

        const B = byCode[biome];
        let kind = KIND.NONE;
        if (E > CL.ore_e && hash(seed, gx, gz, SALT.ore) % 1000 < CL.ore_permille) {
            kind = KIND.ORE;
        } else if (hash(seed, gx, gz, SALT.tree) % 1000 < B.density) {
            kind = KIND.TREE;
        } else {
            const boulder = biome === BIOME.ROCKS ? CL.boulder_permille_rocks : CL.boulder_permille_other;
            if (hash(seed, gx, gz, SALT.boulder) % 1000 < boulder) kind = KIND.BOULDER;
        }
        if (kind === KIND.NONE) return empty;

        const span = CL.offset_max_dm * 2 + 1;
        const cell = {
            kind,
            ox: (hash(seed, gx, gz, SALT.ox) % span) - CL.offset_max_dm,
            oz: (hash(seed, gx, gz, SALT.oz) % span) - CL.offset_max_dm,
            species: -1, size: -1, rare: 0, treasure: 0, biome, X, Z,
        };
        if (kind !== KIND.TREE) return cell;

        // порода по весам биома
        let pick = hash(seed, gx, gz, SALT.species) % B.total;
        let sp = B.list[B.list.length - 1][0];
        for (const [idx, w] of B.list) {
            if (pick < w) { sp = idx; break; }
            pick -= w;
        }
        // редкая порода (шанс растёт с удалённостью)
        if (B.rare >= 0 && hash(seed, gx, gz, SALT.rare) % 10000 < rareChance(tierAtDm(X, Z))) {
            sp = B.rare;
            cell.rare = 1;
        }
        cell.species = sp;
        const sr = hash(seed, gx, gz, SALT.size) % 100;
        cell.size = sr < CL.size_cum[0] ? SIZE.SMALL : sr < CL.size_cum[1] ? SIZE.MEDIUM : SIZE.LARGE;
        cell.treasure = hash(seed, gx, gz, SALT.treasure) % 10000 < CL.treasure_per10k ? 1 : 0;
        return cell;
    }

    const boulderAt = (cx, cz, ci) => cellAt(cx, cz, ci).kind === KIND.BOULDER;
    const oreAt = (cx, cz, ci) => cellAt(cx, cz, ci).kind === KIND.ORE;

    /** Все непустые клетки чанка с позицией и высотой в метрах (для рендера и сервера). */
    function chunkCells(cx, cz) {
        const out = [];
        for (let ci = 0; ci < CELLS_PER_CHUNK * CELLS_PER_CHUNK; ci++) {
            const c = cellAt(cx, cz, ci);
            if (c.kind === KIND.NONE) continue;
            const X = c.X + c.ox;
            const Z = c.Z + c.oz;
            out.push({ id: treeId(cx, cz, ci), ci, kind: c.kind, species: c.species, size: c.size, rare: c.rare, treasure: c.treasure, biome: c.biome, x: X / 10, z: Z / 10, y: heightAtDm(X, Z) / 10 });
        }
        return out;
    }

    return {
        seed,
        species: SP,
        // ядро (дм)
        heightAtDm, biomeAtDm, cellAt, tierAtDm, zoneAtDm, isCityZone, isInsideWorld, distDm,
        fieldE, fieldM, fieldC, fieldR, fieldD,
        boulderAt, oreAt, chunkCells, rareChance,
        waterLevelDm: W.level,
        // обёртки в метрах (floor, отрицательные координаты безопасны)
        heightAt: (x, z) => heightAtDm(Math.floor(x * 10), Math.floor(z * 10)) / 10,
        biomeAt: (x, z) => biomeAtDm(Math.floor(x * 10), Math.floor(z * 10)),
        tier: (x, z) => tierAtDm(Math.floor(x * 10), Math.floor(z * 10)),
        zone: (x, z) => zoneAtDm(Math.floor(x * 10), Math.floor(z * 10)),
    };
}

/** Номер чанка по координате в метрах: именно floor, иначе отрицательные координаты ломаются. */
export const chunkOf = (m) => Math.floor(m / CHUNK_M);
export const treeId = (cx, cz, ci) => `${cx}:${cz}:${ci}`;
export function parseTreeId(id) {
    const m = /^(-?\d+):(-?\d+):(\d+)$/.exec(String(id));
    if (!m) return null;
    const ci = Number(m[3]);
    if (ci > 63) return null;
    return { cx: Number(m[1]), cz: Number(m[2]), ci };
}
export { floorDiv };
