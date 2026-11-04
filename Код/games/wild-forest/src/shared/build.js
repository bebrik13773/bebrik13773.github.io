// Общая логика построек (ДЛ-16). Чистые функции без three.js и сети: клиент подсвечивает призрак так же, как решит сервер,
// а сервер (api/forest/lib/build.php) считает то же самое по тем же тест-векторам (tests/vectors/build.json).
// Каталог и правила приходят с сервера при входе (full.catalog, full.build_rules); значения по умолчанию совпадают с config.php (группа buildings)
// и нужны офлайн-режиму и тестам.

/** Ключи ресурсов постройки → предметы рюкзака. */
export const BUILD_ITEMS = Object.freeze({ logs: 'log', planks: 'plank', ingots: 'ingot' });

export const BUILD_RULES = Object.freeze({
    max_dist: 60,         // центр постройки не дальше от центра поляны, м
    foreign: 40,          // и не ближе к центру чужой деревни, м
    slope: 12,            // наклон площадки, градусы
    gap: 0.4,             // зазор между габаритами, м
    trunk: 0.6,           // радиус ствола при проверке «дерево в габарите», м
    reach: 4,             // стройка тапами: не дальше от края габарита, м
    rot_step: 15,         // шаг поворота, градусы
    stage_walls: 0.33,
    stage_roof: 0.66,
    cancel_full: 0.2,     // отмена до 20% прогресса: вернуть всё
    cancel_partial: 0.7,  // дальше до готовности: 70%
    demolish: 0.5,        // снос готовой: 50%
    max_total: 8,         // сколько построек всего на 1-м уровне игрока
    tap_cost: 0.3,        // энергия за строительный тап
});

/** Каталог по умолчанию (как в config.php). */
export const BUILD_CATALOG = Object.freeze([
    { type: 'warehouse', logs: 30, planks: 0, ingots: 0, coins: 0, taps: 150, r: 2.6, h: 3.2, unlock: 1, max: 3 },
    { type: 'house', logs: 40, planks: 0, ingots: 0, coins: 0, taps: 250, r: 2.8, h: 4.2, unlock: 1, max: 1 },
    { type: 'garden', logs: 15, planks: 6, ingots: 0, coins: 0, taps: 120, r: 3.2, h: 1.6, unlock: 2, max: 1 },
    { type: 'sawmill', logs: 40, planks: 0, ingots: 4, coins: 0, taps: 300, r: 3.0, h: 3.6, unlock: 3, max: 1 },
    { type: 'market', logs: 20, planks: 16, ingots: 0, coins: 0, taps: 250, r: 2.6, h: 3.0, unlock: 3, max: 1 },
    { type: 'workshop', logs: 20, planks: 20, ingots: 6, coins: 0, taps: 400, r: 2.8, h: 3.8, unlock: 4, max: 1 },
    { type: 'quarry', logs: 20, planks: 20, ingots: 6, coins: 1500, taps: 500, r: 3.0, h: 2.6, unlock: 5, max: 1 },
    { type: 'smelter', logs: 30, planks: 20, ingots: 8, coins: 2500, taps: 600, r: 2.4, h: 4.4, unlock: 6, max: 1 },
    { type: 'forge', logs: 20, planks: 30, ingots: 10, coins: 3500, taps: 700, r: 2.4, h: 4.0, unlock: 8, max: 1 },
]);

export const BUILD_STATES = Object.freeze(['blueprint', 'building', 'ready', 'flooded']);

/** Ресурсы постройки предметами: { log: 30, ... } (только больше нуля). Монеты отдельно, buildCoins. */
export function buildCost(spec) {
    const out = {};
    for (const key of Object.keys(BUILD_ITEMS)) {
        const n = Math.trunc(Number(spec && spec[key]) || 0);
        if (n > 0) out[BUILD_ITEMS[key]] = n;
    }
    return out;
}

export function buildCoins(spec) {
    return Math.max(0, Math.trunc(Number(spec && spec.coins) || 0));
}

/** Описание типа в каталоге или null. */
export function findSpec(catalog, type) {
    return (catalog || BUILD_CATALOG).find((s) => s.type === type) || null;
}

/** Перекрываются ли два круга с зазором. */
export function circlesOverlap(x1, z1, r1, x2, z2, r2, gap) {
    return Math.hypot(x1 - x2, z1 - z2) < r1 + r2 + gap - 1e-9;
}

/** Наклон площадки, градусы: по высотам в центре и в четырёх точках на краю габарита. */
export function slopeDeg(heights, r) {
    if (!heights || heights.length < 2 || !(r > 0)) return 0;
    return (Math.atan((Math.max(...heights) - Math.min(...heights)) / (2 * r)) * 180) / Math.PI;
}

/** Пять точек замера высоты: центр и четыре на краю габарита. */
export function samplePoints(x, z, r) {
    return [[x, z], [x + r, z], [x - r, z], [x, z + r], [x, z - r]];
}

/** Видимая стадия по прогрессу: foundation, walls, roof, ready. */
export function buildStage(progress, needed, rules = BUILD_RULES) {
    if (!(needed > 0) || progress >= needed) return 'ready';
    const f = Math.max(0, progress) / needed;
    if (f >= rules.stage_roof) return 'roof';
    return f >= rules.stage_walls ? 'walls' : 'foundation';
}

/** Доля возврата: отмена до 20% — 100%, дальше до готовности — 70%; снос готовой — 50%. null = нельзя. */
export function refundK(state, progress, needed, rules = BUILD_RULES) {
    if (state === 'ready') return rules.demolish;
    if (state !== 'blueprint' && state !== 'building') return null;
    const f = needed > 0 ? Math.max(0, progress) / needed : 0;
    return f <= rules.cancel_full + 1e-9 ? 1 : rules.cancel_partial;
}

/** Возврат: ресурсы и монеты, умноженные на долю (целые, вниз). */
export function refund(spec, k) {
    const items = {};
    for (const [item, qty] of Object.entries(buildCost(spec))) {
        const n = Math.floor(qty * k + 1e-9);
        if (n > 0) items[item] = n;
    }
    return { items, coins: Math.floor(buildCoins(spec) * k + 1e-9) };
}

/** Сколько тапов стройки принять: не больше запрошенного, оставшегося, токенов бюджета и энергии. { n, limit: 'none'|'tokens'|'energy' }. */
export function tapPlan(want, left, tokens, energy, cost) {
    const byTokens = Math.floor(Math.max(0, tokens) + 1e-9);
    const byEnergy = cost > 0 ? Math.floor(Math.max(0, energy) / cost + 1e-9) : want;
    const cap = Math.max(0, Math.min(want, left));
    const n = Math.max(0, Math.min(cap, byTokens, byEnergy));
    let limit = 'none';
    if (n < cap) limit = byEnergy < byTokens ? 'energy' : 'tokens';
    return { n, limit };
}

/**
 * Что списать из запасов. stock: { контейнер: { предмет: штук } }. Порядок: рюкзак, ящик, склады (bld:N по возрастанию).
 * Возвращает [[контейнер, предмет, штук], ...] или null, если не хватает.
 */
export function planTake(stock, need) {
    const order = [];
    for (const c of ['pack', 'crate']) if (stock[c]) order.push(c);
    const rest = Object.keys(stock).filter((c) => c.startsWith('bld:'));
    rest.sort((a, b) => a.localeCompare(b, 'en', { numeric: true }));
    const all = order.concat(rest);
    const plan = [];
    for (const [item, qty] of Object.entries(need)) {
        let left = qty;
        for (const c of all) {
            if (left <= 0) break;
            const have = (stock[c] && stock[c][item]) || 0;
            const take = Math.min(have, left);
            if (take > 0) { plan.push([c, item, take]); left -= take; }
        }
        if (left > 0) return null;
    }
    return plan;
}

/**
 * Проверка места без сети. Вход: level, unlock, total, max_total, same, max_same, inside_world, city, dist_village, foreign_dist,
 * water, slope_deg, trees, overlap. Возвращает код причины или null, если можно.
 */
export function placeError(c, rules = BUILD_RULES) {
    if (c.level < c.unlock) return 'locked';
    if (c.total >= c.max_total) return 'limit';
    if (c.same >= c.max_same) return 'limit_type';
    if (!c.inside_world) return 'world_edge';
    if (c.city) return 'city';
    if (c.dist_village > rules.max_dist + 1e-9) return 'far_from_village';
    if (c.foreign_dist < rules.foreign - 1e-9) return 'foreign';
    if (c.water) return 'water';
    if (c.slope_deg > rules.slope + 1e-9) return 'slope';
    if (c.trees > 0) return 'trees';
    if (c.overlap) return 'overlap';
    return null;
}

/** Поворот в градусах: кратно шагу, 0…359. */
export function normRot(deg, step = BUILD_RULES.rot_step) {
    const s = Math.max(1, step);
    const n = Math.round(deg / s) * s;
    return ((n % 360) + 360) % 360;
}

/** Нехватка ресурсов для постройки: { log: 5, coins: 100 } (только чего не хватает) или {}. have: { предмет: штук } суммарно по рюкзаку, ящику и складам. */
export function shortage(spec, have, coins) {
    const out = {};
    for (const [item, qty] of Object.entries(buildCost(spec))) {
        const lack = qty - ((have && have[item]) || 0);
        if (lack > 0) out[item] = lack;
    }
    const lackCoins = buildCoins(spec) - (coins || 0);
    if (lackCoins > 0) out.coins = lackCoins;
    return out;
}
