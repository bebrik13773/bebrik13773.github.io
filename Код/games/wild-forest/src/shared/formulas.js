// Общие формулы (клиент, тесты, зеркало в api/forest/lib/formulas.php). Баланс берётся из api/forest/config.php (hero.*),
// здесь значения по умолчанию совпадают с ним; расходятся только если владелец поменяет config.php (тогда клиент получит часть конфига по API).
export const HERO = Object.freeze({
    walkSpeed: 3.2,        // м/с налегке по лесу
    loadSlowdown: 0.55,    // во сколько раз максимум замедляет полный рюкзак
    loadExponent: 1.2,
    roadBonus: Object.freeze([0, 0.10, 0.25, 0.45]), // нет дороги, земля, камень, асфальт
    bootsBonus: 0.10,
    moveSlack: 1.35,       // запас сервера на дрожание сети
    moveConstM: 3,         // и постоянный запас, м
});

/**
 * Скорость бобра, м/с: v = v0 × (1 − 0.55 × min(1, груз/вместимость)^1.2) × (1 + дорога) × (1 + сапоги) × погода.
 * @param {{load?:number, capacity?:number, road?:number, boots?:boolean, weather?:number}} p
 */
export function heroSpeed({ load = 0, capacity = 40, road = 0, boots = false, weather = 1 } = {}) {
    const frac = capacity > 0 ? Math.min(1, Math.max(0, load) / capacity) : 1;
    const carry = 1 - HERO.loadSlowdown * Math.pow(frac, HERO.loadExponent);
    const roadK = 1 + (HERO.roadBonus[Math.max(0, Math.min(HERO.roadBonus.length - 1, road | 0))] || 0);
    const bootsK = 1 + (boots ? HERO.bootsBonus : 0);
    return HERO.walkSpeed * carry * roadK * bootsK * weather;
}

/** Сколько метров сервер позволяет пройти за dt секунд при максимальной скорости vMax: vMax × dt × 1.35 + 3 м. */
export function moveAllowance(vMax, dtSec) {
    return vMax * Math.max(0, dtSec) * HERO.moveSlack + HERO.moveConstM;
}

/**
 * Проверка журнала позиций (точки { t (сек), x, z }) по правилу сервера. Возвращает { ok, bad: [{ i, dist, allowed }] }.
 * vMaxOf(point) даёт максимальную скорость на этом отрезке (учитывает груз, дорогу, сапоги).
 */
export function checkTrack(points, vMaxOf) {
    const bad = [];
    for (let i = 1; i < points.length; i += 1) {
        const a = points[i - 1];
        const b = points[i];
        const dist = Math.hypot(b.x - a.x, b.z - a.z);
        const allowed = moveAllowance(vMaxOf(b), b.t - a.t);
        if (dist > allowed + 1e-9) bad.push({ i, dist, allowed });
    }
    return { ok: bad.length === 0, bad };
}

// Уровень игрока (ДЛ-10). Чтобы перейти с уровня l на l+1, нужно round(100 × l^1.6) опыта. Максимум 50.
export const LEVEL = Object.freeze({ xpBase: 100, xpExp: 1.6, max: 50 });

/** Опыт, нужный для перехода с уровня l на следующий. */
export function xpToNext(l) {
    return Math.round(LEVEL.xpBase * Math.pow(l, LEVEL.xpExp));
}

/** Уровень и остаток опыта по общему опыту. */
export function levelFromXp(xp) {
    let level = 1;
    let rest = Math.max(0, Math.floor(xp));
    while (level < LEVEL.max && rest >= xpToNext(level)) {
        rest -= xpToNext(level);
        level += 1;
    }
    return { level, into: rest, need: level >= LEVEL.max ? 0 : xpToNext(level) };
}

// ── Рубка (ДЛ-11) ───────────────────────────────────────────────────────
// Баланс совпадает с api/forest/config.php (trees.*, hero.cost_chop, limits.*); зеркало PHP: api/forest/lib/chop.php.
// Порядок пород равен порядку в species.json (индекс породы в клетке мира).
export const SPECIES_ORDER = Object.freeze(['spruce', 'pine', 'birch', 'aspen', 'maple', 'oak', 'black_alder', 'gold_birch', 'ancient_cedar', 'iron_oak']);
export const TREES = Object.freeze({
    hpBase: 8,
    hpTierK: 0.30,
    sizeK: Object.freeze([0.8, 1.0, 1.5]),               // малое, среднее, большое
    costChop: 0.5,                                        // энергия за удар
    maxTapsPerS: 6,
    tapBucket: 40,
    axePower: Object.freeze([0, 1.0, 1.4, 2.0, 2.8, 4.0, 5.6]), // по уровню топора, 0 не используется
    // порода: [H, брёвна мал/ср/бол]
    species: Object.freeze({
        spruce: Object.freeze({ h: 1.0, logs: Object.freeze([2, 3, 5]) }),
        pine: Object.freeze({ h: 0.9, logs: Object.freeze([2, 3, 4]) }),
        birch: Object.freeze({ h: 0.7, logs: Object.freeze([1, 2, 3]) }),
        aspen: Object.freeze({ h: 0.8, logs: Object.freeze([2, 3, 4]) }),
        maple: Object.freeze({ h: 1.3, logs: Object.freeze([2, 3, 4]) }),
        oak: Object.freeze({ h: 1.8, logs: Object.freeze([3, 4, 6]) }),
        black_alder: Object.freeze({ h: 1.2, logs: Object.freeze([2, 3, 3]) }),
        gold_birch: Object.freeze({ h: 2.5, logs: Object.freeze([2, 3, 4]) }),
        ancient_cedar: Object.freeze({ h: 3.2, logs: Object.freeze([4, 6, 8]) }),
        iron_oak: Object.freeze({ h: 4.5, logs: Object.freeze([5, 7, 10]) }),
    }),
});

/** Сила топора по уровню (1..6). */
export function axePower(level) {
    const l = Math.max(1, Math.min(TREES.axePower.length - 1, level | 0));
    return TREES.axePower[l];
}

/** Прочность дерева: 8 × H × k_размер × (1 + 0.30 × tier). speciesId строкой, size 0..2. */
export function treeHp(speciesId, size, tier) {
    const sp = TREES.species[speciesId];
    if (!sp) return 0;
    return TREES.hpBase * sp.h * TREES.sizeK[Math.max(0, Math.min(2, size | 0))] * (1 + TREES.hpTierK * Math.max(0, tier | 0));
}

/** Сколько ударов нужно топором данной силы (урон тапа = сила). Допуск 1e-9 против погрешностей плавающей точки. */
export function chopNeed(hp, power) {
    return Math.max(1, Math.ceil(hp / power - 1e-9));
}

/** Энергия за рубку дерева с need ударами. */
export function chopCost(need) {
    return need * TREES.costChop;
}

/** Число брёвен по породе и размеру. */
export function treeLogs(speciesId, size) {
    const sp = TREES.species[speciesId];
    return sp ? sp.logs[Math.max(0, Math.min(2, size | 0))] : 0;
}

/**
 * Бюджет тапов («ведро токенов»): вместимость 40, пополнение 6 в секунду. tokens и tsMs из прошлого состояния.
 * Возвращает { ok, tokens } (tokens уже с пополнением; при ok списано want, иначе не списано).
 */
export function tapTake(tokens, tsMs, nowMs, want) {
    const refill = Math.min(TREES.tapBucket, tokens + (Math.max(0, nowMs - tsMs) / 1000) * TREES.maxTapsPerS);
    if (want <= refill + 1e-9) return { ok: true, tokens: refill - want };
    return { ok: false, tokens: refill };
}
