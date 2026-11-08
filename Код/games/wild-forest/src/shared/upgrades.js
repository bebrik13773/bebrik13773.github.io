// Улучшения бобра (ДЛ-18): чистая логика окна верстака без DOM. Цены приходят от сервера (upgrade_catalog), здесь только разбор и подсказки «было → станет».
import { axePower, packCapacity, energyMax } from './formulas.js';

export const KINDS = Object.freeze(['axe', 'pack', 'stamina', 'lamp']);
export const ONCE = Object.freeze(['pickaxe', 'raincoat', 'warm', 'boots']);
export const START = Object.freeze({ axe: 1, pack: 1, stamina: 1, lamp: 0 });

/** Самый высокий уровень вида по каталогу сервера (ключи levels[kind]). */
export function maxLevelOf(catalog, kind) {
    const lv = catalog && catalog.levels && catalog.levels[kind] ? Object.keys(catalog.levels[kind]).map(Number) : [];
    return lv.length ? Math.max(...lv) : START[kind];
}

/** Что даёт уровень: число для подсказки «было → станет». */
export function levelValue(kind, lvl, catalog) {
    if (kind === 'axe') return axePower(lvl);
    if (kind === 'pack') return packCapacity(lvl);
    if (kind === 'stamina') return energyMax(lvl);
    if (kind === 'lamp') return lvl > 0 && catalog && catalog.lamp_bonus_m ? Number(catalog.lamp_bonus_m[lvl]) || 0 : 0;
    return 0;
}

/** Нехватка: {coins?, предмет?: не хватает штук}. */
export function lackOf(spec, have, coins) {
    const lack = {};
    if ((Number(spec.coins) || 0) > coins) lack.coins = spec.coins - coins;
    for (const [item, qty] of Object.entries(spec.items || {})) {
        const miss = (Number(qty) || 0) - (Number(have[item]) || 0);
        if (miss > 0) lack[item] = miss;
    }
    return lack;
}

/**
 * Строки окна верстака. state: { levels:{axe,pack,stamina,lamp}, gear:{}, hotbar:[] }.
 * Строка: { id, kind, to, status: ok|lack|max|owned|full, spec, lack, from, next }.
 */
export function benchRows({ catalog, levels, gear = {}, hotbar = [], have = {}, coins = 0, slots = 6 }) {
    const rows = [];
    for (const kind of KINDS) {
        const cur = Number(levels && levels[kind]) || START[kind];
        const max = maxLevelOf(catalog, kind);
        const row = { id: kind, kind, level: cur, max };
        if (cur >= max) rows.push({ ...row, status: 'max', from: levelValue(kind, cur, catalog) });
        else {
            const spec = catalog.levels[kind][String(cur + 1)];
            const lack = lackOf(spec, have, coins);
            rows.push({ ...row, to: cur + 1, spec, lack, status: Object.keys(lack).length ? 'lack' : 'ok', from: levelValue(kind, cur, catalog), next: levelValue(kind, cur + 1, catalog) });
        }
    }
    for (const name of ONCE) {
        const spec = catalog && catalog.once ? catalog.once[name] : null;
        if (!spec) continue;
        const owned = name === 'pickaxe' ? hotbar.includes('pickaxe') : Boolean(gear[name]);
        if (owned) { rows.push({ id: name, kind: name, status: 'owned', spec }); continue; }
        if (name === 'pickaxe' && hotbar.length >= slots) { rows.push({ id: name, kind: name, status: 'full', spec }); continue; }
        const lack = lackOf(spec, have, coins);
        rows.push({ id: name, kind: name, spec, lack, status: Object.keys(lack).length ? 'lack' : 'ok' });
    }
    return rows;
}

/** Снаряжение для модели бобра: уровни топора, рюкзака, фонаря. */
export function gearOfLevels(levels) {
    return { axe: Number(levels.axe) || 1, pack: Number(levels.pack) || 1, lamp: Number(levels.lamp) || 0 };
}
