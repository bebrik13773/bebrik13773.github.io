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

// ── Энергия, сон и еда (ДЛ-12) ───────────────────────────────────────
// Зеркало api/forest/lib/energy.php; значения совпадают с config.php (hero.*, limits.rest_credit_cap_s).
export const ENERGY = Object.freeze({
    base: 100, perStaminaLvl: 10, staminaMaxLvl: 6,
    walkPerM: 0.015,     // расход на метр пути
    regenHome: 1.0,      // рядом с домом (стоя или во сне), в секунду
    regenRest: 0.1,      // стоя в лесу
    regenSleep: 0.2,     // сон в лесу
    wake: 20,            // до этого значения бобёр спит
    eps: 0.0001,         // «ноль» энергии
    creditCapS: 120,     // сколько секунд между sync засчитывается восстановление
    eatPauseS: 5,        // пауза между приёмами еды
    eatAnimS: 3,         // анимация еды
    homeRadiusM: 6,
    fish: Object.freeze({ small: 25, big: 45 }),
});

/** Максимум энергии: 100 на 1 уровне выносливости, +10 за каждый следующий (до 6 уровня). */
export function energyMax(staminaLvl = 1) {
    const lvl = Math.max(1, Math.min(ENERGY.staminaMaxLvl, staminaLvl | 0));
    return ENERGY.base + ENERGY.perStaminaLvl * (lvl - 1);
}

/** Расход на ходьбу: метры × 0.015 × множитель погоды. */
export function walkCost(distM, mult = 1) {
    return Math.max(0, distM) * ENERGY.walkPerM * mult;
}

/** Скорость восстановления, единиц в секунду: сон 0.2 (дома 1.0), стоя 0.1 (дома 1.0), в движении и за работой 0. */
export function energyRate({ sleeping = false, home = false, still = false } = {}) {
    if (sleeping) return home ? ENERGY.regenHome : ENERGY.regenSleep;
    if (!still) return 0;
    return home ? ENERGY.regenHome : ENERGY.regenRest;
}

/** Шаг ленивого расчёта между двумя sync: { energy, sleeping }. Восстановление ограничено creditCapS (офлайн не копится). */
export function energyTick({ energy, max, dt, dist = 0, still = false, home = false, sleeping = false, mult = 1 }) {
    let e = Math.max(0, energy - walkCost(dist, mult));
    let sleep = Boolean(sleeping);
    if (!sleep && e <= ENERGY.eps) sleep = true; // силы кончились: засыпает на месте
    const t = Math.max(0, Math.min(dt, ENERGY.creditCapS));
    e = Math.min(max, e + energyRate({ sleeping: sleep, home, still }) * t);
    if (sleep && e >= ENERGY.wake) sleep = false;
    return { energy: e, sleeping: sleep };
}

/** Сколько энергии даёт еда; null, если это не еда. */
export function eatGain(item) {
    const map = { fish: 'small', fish_small: 'small', fish_big: 'big' };
    return map[item] ? ENERGY.fish[map[item]] : null;
}

// ── Рюкзак и вес ноши (ДЛ-13) ──────────────────────────────────────────
// Баланс совпадает с api/forest/config.php (hero.weights_kg, pack.*); зеркало PHP: api/forest/lib/pack_weight.php.
export const PACK = Object.freeze({
    baseKg: 40,
    perLvlKg: 10,
    maxLvl: 8,
    pickupR: 3.0,        // сервер принимает подбор не дальше 3 м
    autoPickupR: 1.5,    // автоподбор брёвен на клиенте
    logMinKg: 4,
    logMaxKg: 10,
    weights: Object.freeze({ plank: 3, ore: 8, ingot: 5, stone: 10, boulder: 10, fish: 1, fish_small: 1, fish_big: 1, seed: 0.1, seed_rare: 0.1 }),
});

/** Вместимость рюкзака, кг, по уровню: 40 и +10 за уровень (до 8). */
export function packCapacity(lvl = 1) {
    const l = Math.max(1, Math.min(PACK.maxLvl, lvl | 0));
    return PACK.baseKg + PACK.perLvlKg * (l - 1);
}

/** Вес одной штуки, кг (бревно среднее, 7). */
export function itemKg(item) {
    if (item === 'log') return (PACK.logMinKg + PACK.logMaxKg) / 2;
    return PACK.weights[item] !== undefined ? PACK.weights[item] : 1;
}

/** Вес набора { предмет: штук }, кг, до сотых. */
export function packKg(items) {
    let kg = 0;
    for (const key of Object.keys(items || {})) kg += itemKg(key) * Math.max(0, Math.floor(items[key]));
    return Math.round(kg * 100) / 100;
}

/** Сколько штук предмета ещё влезет (0…qty). */
export function packFit(capacity, loadKg, item, qty) {
    const per = itemKg(item);
    if (per <= 0) return Math.max(0, qty);
    const room = capacity - loadKg;
    if (room <= 0) return 0;
    return Math.max(0, Math.min(qty, Math.floor(room / per + 1e-9)));
}

/** Груз для проверки скорости: рюкзак минус брошенное в том же пакете (зеркало wf_pack_move_load). */
export function moveLoad(items, actions) {
    const left = {};
    for (const key of Object.keys(items || {})) left[key] = Math.max(0, Math.floor(items[key]));
    for (const a of actions || []) {
        if (!a || a.type !== 'drop' || typeof a.item !== 'string') continue;
        const q = Math.floor(Number(a.qty));
        if (left[a.item] !== undefined && q > 0) left[a.item] = Math.max(0, left[a.item] - q);
    }
    return packKg(left);
}
