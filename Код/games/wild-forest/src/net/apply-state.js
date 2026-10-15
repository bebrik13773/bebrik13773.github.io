// Перенос состояния сервера в интерфейс (ДЛ-10). Чистая логика: объекты hud, inventory, world передаются снаружи.
// Вес одной штуки для панели рюкзака (бревно среднее: сервер считает по размеру дерева, итог веса берёт с сервера).
export const ITEM_KG = Object.freeze({ log: 7, plank: 3, ore: 8, ingot: 5, stone: 10, boulder: 10, fish_small: 1, fish_big: 1, fish: 1, seed: 0.1 });
const EDIBLE = new Set(['fish', 'fish_small', 'fish_big']);

/** { key: qty } → список для панели рюкзака. */
export function packToItems(pack) {
    return Object.keys(pack || {}).map((key) => ({ key, qty: pack[key], kg: ITEM_KG[key] != null ? ITEM_KG[key] : 1, edible: EDIBLE.has(key) }));
}

/** Применяет легкое состояние (энергия, монеты, вес, рюкзак, уровень) к HUD и панели рюкзака. */
export function applyLightState(state, { hud, inventory }) {
    if (!state) return;
    if (hud) {
        hud.set({
            energy: Number(state.energy), energyMax: Number(state.energy_max) || 100,
            coins: Number(state.coins) || 0,
            packKg: Number(state.weight) || 0, packCap: Number(state.capacity) || 40,
        });
    }
    if (inventory) {
        inventory.setCapacity(Number(state.capacity) || 40);
        inventory.setItems(packToItems(state.pack));
    }
}

/** Начальные данные для сцены из полного состояния: позиция, поляна, пруд. */
export function startFromState(full) {
    if (!full || !full.pos) return null;
    return {
        x: Number(full.pos.x), z: Number(full.pos.z),
        clearings: Array.isArray(full.clearings) ? full.clearings.map((c) => ({ x: Number(c.x), z: Number(c.z), r: Number(c.r) })) : [],
        pond: full.pond || null,
        felled: Array.isArray(full.felled) ? full.felled.map(String) : [],
        drops: Array.isArray(full.drops) ? full.drops : [],
    };
}
