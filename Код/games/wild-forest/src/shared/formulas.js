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
