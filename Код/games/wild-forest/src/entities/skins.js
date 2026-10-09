// Скины бобра (ДЛ-07). Скин это данные: палитра цветов частей тела и слоты аксессуаров.
// Формат сохраняется как JSON (числа цветов, строки названий), чтобы потом приходить с сервера (магазин города, награды).
//
// {
//   id: 'classic', name: 'Классический',
//   palette: { fur, furDark, belly, tail, tailPattern, feet, nose, tooth, eyeWhite, pupil, earInner, pack, packDark, packRoll, gold, metal, wood, log, logCap, fish },
//   accessories: { hat: { kind, color, color2 } | null, scarf: { kind, color } | null, glasses: { kind, color } | null }
// }
// Любое поле палитры можно не указывать: подставится цвет классического бобра.

export const PALETTE_KEYS = Object.freeze([
    'fur', 'furDark', 'belly', 'tail', 'tailPattern', 'feet', 'nose', 'tooth', 'eyeWhite', 'pupil', 'earInner',
    'pack', 'packDark', 'packRoll', 'gold', 'metal', 'wood', 'log', 'logCap', 'fish',
]);

// Какие виды аксессуаров умеет рисовать модель (см. beaver-model.js).
export const ACCESSORY_KINDS = Object.freeze({
    hat: Object.freeze(['beanie', 'cap', 'tophat']),
    scarf: Object.freeze(['wool']),
    glasses: Object.freeze(['round']),
});

export const CLASSIC_PALETTE = Object.freeze({
    fur: 0x9a6a3a,
    furDark: 0x6e4727,
    belly: 0xd9b58a,
    tail: 0x4a3a30,
    tailPattern: 0x362a24,
    feet: 0x5a4030,
    nose: 0x241a18,
    tooth: 0xfff2d6,
    eyeWhite: 0xffffff,
    pupil: 0x1a1210,
    earInner: 0xd89a8a,
    pack: 0xb8742e,
    packDark: 0x7d4a1d,
    packRoll: 0xc9b98a,
    gold: 0xe6b422,
    metal: 0x8e9aa3,
    wood: 0x8a5a2b,
    log: 0x9b6a3a,
    logCap: 0xe3c18a,
    fish: 0x8aa3b5,
});

export const DEFAULT_SKIN = Object.freeze({
    id: 'classic',
    name: 'Классический',
    palette: CLASSIC_PALETTE,
    accessories: Object.freeze({ hat: null, scarf: null, glasses: null }),
});

// Примеры для обзора на странице tests/beaver.html и для проверки подмены. Настоящий каталог появится вместе с магазином.
export const SAMPLE_SKINS = Object.freeze([
    DEFAULT_SKIN,
    Object.freeze({
        id: 'winter',
        name: 'Зимний',
        palette: { fur: 0xc9c6c0, furDark: 0x8d8a96, belly: 0xffffff, tail: 0x5d6475, tailPattern: 0x424859, feet: 0x6c6f7d, earInner: 0xf2b6c4, pack: 0x3d7bb8, packDark: 0x2a5a8a },
        accessories: {
            hat: { kind: 'beanie', color: 0xd9453a, color2: 0xffffff },
            scarf: { kind: 'wool', color: 0x3a78d9 },
            glasses: null,
        },
    }),
    Object.freeze({
        id: 'night',
        name: 'Ночной',
        palette: { fur: 0x4b4660, furDark: 0x2f2b40, belly: 0x8d84a8, tail: 0x2a2636, tailPattern: 0x1a1724, feet: 0x2f2b40, earInner: 0x9f6f93, pack: 0x35506b, packDark: 0x223548 },
        accessories: {
            hat: { kind: 'cap', color: 0x2b2e4a, color2: 0xe6b422 },
            scarf: null,
            glasses: { kind: 'round', color: 0xe6b422 },
        },
    }),
    Object.freeze({
        id: 'golden',
        name: 'Золотой',
        palette: { fur: 0xd9a93a, furDark: 0xa8741f, belly: 0xfff0b0, tail: 0x8a5a1a, tailPattern: 0x6a4210, feet: 0x8a5a1a, pack: 0x8a3a2a, packDark: 0x5a2418 },
        accessories: {
            hat: { kind: 'tophat', color: 0x222222, color2: 0xe6b422 },
            scarf: null,
            glasses: null,
        },
    }),
]);

const isColor = (v) => Number.isInteger(v) && v >= 0 && v <= 0xffffff;

function cleanAccessory(slot, value) {
    if (!value || typeof value !== 'object') return null;
    if (!ACCESSORY_KINDS[slot].includes(value.kind)) return null;
    return {
        kind: value.kind,
        color: isColor(value.color) ? value.color : 0xd9453a,
        color2: isColor(value.color2) ? value.color2 : 0xffffff,
    };
}

/** Приводит любой скин (в том числе неполный или битый) к полной рабочей форме. Неизвестные поля отбрасываются. */
export function resolveSkin(skin) {
    const source = skin && typeof skin === 'object' ? skin : DEFAULT_SKIN;
    const palette = {};
    for (const key of PALETTE_KEYS) {
        const v = source.palette ? source.palette[key] : undefined;
        palette[key] = isColor(v) ? v : CLASSIC_PALETTE[key];
    }
    const acc = source.accessories || {};
    return {
        id: typeof source.id === 'string' ? source.id : 'custom',
        name: typeof source.name === 'string' ? source.name : 'Свой',
        palette,
        accessories: {
            hat: cleanAccessory('hat', acc.hat),
            scarf: cleanAccessory('scarf', acc.scarf),
            glasses: cleanAccessory('glasses', acc.glasses),
        },
    };
}

/** Снаряжение бобра (уровни из ДЛ-18): топор 1…6, рюкзак 0…8 (0 — без рюкзака), фонарь 0…4 (0 — нет). */
export const GEAR_LIMITS = Object.freeze({ axe: [1, 6], pack: [0, 8], lamp: [0, 4] });

export function resolveGear(gear) {
    const g = gear && typeof gear === 'object' ? gear : {};
    const pick = (key, fallback) => {
        const [lo, hi] = GEAR_LIMITS[key];
        const v = Number.isFinite(g[key]) ? Math.round(g[key]) : fallback;
        return Math.min(hi, Math.max(lo, v));
    };
    return { axe: pick('axe', 1), pack: pick('pack', 1), lamp: pick('lamp', 0) };
}
