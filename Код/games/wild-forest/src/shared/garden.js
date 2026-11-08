// Сад (ДЛ-19): чистая логика без DOM и Three.js. Зеркало серверных мест посадки (lib/garden.php) и подготовка строк окна сада.
// Рост считает только сервер; клиент показывает долю и оставшееся время, которые пришли в ответе.

export const GARDEN = Object.freeze({ r: 3.2, ringExtraM: 1.6, ringSlots: 14, slots: 6, slotsPerLvl: 4, reachExtraM: 4.5 });

/** Мест посадки на уровне сада (зеркало wf_garden_slots). */
export function gardenSlots(level) {
    return Math.min(GARDEN.ringSlots, GARDEN.slots + GARDEN.slotsPerLvl * (Math.max(1, Math.trunc(level) || 1) - 1));
}

/** Место посадки номер slot вокруг центра сада (зеркало wf_garden_slot_pos, два знака после запятой). */
export function slotPos(bx, bz, slot) {
    const r = GARDEN.r + GARDEN.ringExtraM;
    const a = (2 * Math.PI * slot) / GARDEN.ringSlots;
    return { x: Math.round((bx + Math.cos(a) * r) * 100) / 100, z: Math.round((bz + Math.sin(a) * r) * 100) / 100 };
}

/** Порода по предмету-семени ('seed_oak' → 'oak') или null. */
export function seedSpecies(item) {
    const s = String(item || '');
    return s.startsWith('seed_') && s.length > 5 ? s.slice(5) : null;
}

/** Стадия роста по доле: 0 росток, 1 молодое, 2 среднее, 3 взрослое (зеркало wf_garden_stage). */
export function stageOf(frac) {
    const f = Number(frac) || 0;
    return f >= 1 ? 3 : f >= 0.66 ? 2 : f >= 0.33 ? 1 : 0;
}

/** Семена из рюкзака: [{ item, species, qty }], по убыванию количества. */
export function seedsInPack(pack) {
    const out = [];
    for (const [item, qty] of Object.entries(pack || {})) {
        const sp = seedSpecies(item);
        if (sp && Number(qty) > 0) out.push({ item, species: sp, qty: Math.trunc(Number(qty)) });
    }
    return out.sort((a, b) => b.qty - a.qty || a.item.localeCompare(b.item));
}

/** «3 д 4 ч», «5 ч 10 мин», «12 мин» из секунд. */
export function leftText(sec) {
    const s = Math.max(0, Math.round(Number(sec) || 0));
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (d > 0) return `${d} д${h ? ` ${h} ч` : ''}`;
    if (h > 0) return `${h} ч${m ? ` ${m} мин` : ''}`;
    return `${Math.max(1, m)} мин`;
}

/**
 * Строки окна сада: по одному на место. saplings: ответ сервера; slots: сколько мест открыто.
 * Состояния: empty | growing | dry | grown. Для растущих считаем оставшееся время от момента получения (age секунд назад).
 */
export function gardenRows({ buildingId, level, saplings = [], age = 0 }) {
    const n = gardenSlots(level);
    const bySlot = new Map();
    for (const s of saplings) if (Number(s.building) === Number(buildingId)) bySlot.set(Number(s.slot), s);
    const rows = [];
    for (let i = 0; i < n; i += 1) {
        const s = bySlot.get(i);
        if (!s) { rows.push({ slot: i, state: 'empty' }); continue; }
        const left = s.state === 'growing' ? Math.max(0, Number(s.left_s) - age) : 0;
        rows.push({ slot: i, state: s.state, id: s.id, species: s.species, frac: Number(s.frac) || 0, stage: s.stage, watered: Boolean(s.watered), big: Boolean(s.big), need: Number(s.need) || 0, leftS: left });
    }
    return rows;
}
