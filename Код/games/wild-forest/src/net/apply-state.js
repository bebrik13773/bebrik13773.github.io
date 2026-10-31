// Перенос состояния сервера в интерфейс (ДЛ-10). Чистая логика: объекты hud, inventory, world передаются снаружи.
// Вес одной штуки для панели рюкзака (бревно среднее: сервер считает по размеру дерева, итог веса берёт с сервера).
import { itemKg } from '../shared/formulas.js';
export const ITEM_KG = Object.freeze({ log: 7, plank: 3, ore: 8, ingot: 5, stone: 10, boulder: 10, fish_small: 1, fish_big: 1, fish: 1, seed: 0.1 });
const EDIBLE = new Set(['fish', 'fish_small', 'fish_big']);

/** { key: qty } → список для панели рюкзака. */
export function packToItems(pack) {
    return Object.keys(pack || {}).map((key) => ({ key, qty: pack[key], kg: itemKg(key), edible: EDIBLE.has(key) }));
}

/** Применяет легкое состояние (энергия, монеты, вес, рюкзак, уровень) к HUD и панели рюкзака. */
export function applyLightState(state, { hud, inventory, crate }) {
    if (!state) return;
    if (hud) {
        hud.set({
            energy: Number(state.energy), energyMax: Number(state.energy_max) || 100, sleeping: Boolean(state.sleeping),
            coins: Number(state.coins) || 0,
            packKg: Number(state.weight) || 0, packCap: Number(state.capacity) || 40,
        });
    }
    if (inventory) {
        inventory.setCapacity(Number(state.capacity) || 40);
        inventory.setItems(packToItems(state.pack));
    }
    // Ящик-тайник и остаток суточного лимита скупщика (ДЛ-14)
    if (crate && typeof crate.onState === 'function') crate.onState(state);
}

/** Начальные данные для сцены из полного состояния: позиция, поляна, пруд. */
export function startFromState(full) {
    if (!full || !full.pos) return null;
    return {
        x: Number(full.pos.x), z: Number(full.pos.z),
        clearings: Array.isArray(full.clearings) ? full.clearings.map((c) => ({ x: Number(c.x), z: Number(c.z), r: Number(c.r) })) : [],
        pond: full.pond || null,
        village: normalizeVillage(full.village),
        felled: Array.isArray(full.felled) ? full.felled.map(String) : [],
        uprooted: Array.isArray(full.uprooted) ? full.uprooted.map(String) : [],
        drops: Array.isArray(full.drops) ? full.drops : [],
    };
}

/** Места ящика и подводы из ответа сервера: { name, x, z, crate: {x,z}, buyer: {x,z,prices,cap} } или null. */
export function normalizeVillage(v) {
    if (!v || typeof v !== 'object') return null;
    const spot = (p) => (p && Number.isFinite(Number(p.x)) && Number.isFinite(Number(p.z)) ? { x: Number(p.x), z: Number(p.z) } : null);
    const crate = spot(v.crate);
    const buyerSpot = spot(v.buyer);
    const buyer = buyerSpot ? { ...buyerSpot, prices: { ...((v.buyer && v.buyer.prices) || {}) }, cap: Number(v.buyer && v.buyer.cap) || 0 } : null;
    if (!crate && !buyer) return null;
    return { name: String(v.name || ''), crate, buyer };
}
