import { S } from './strings.ru.js';

// Чистая логика HUD без DOM: уровни тревоги, фаза суток, сезон, формат чисел и имён.
// Работает и в браузере, и в Node-тестах. Часть расчётов временная: настоящие часы, сезон и погода придут из shared/time.js (ДЛ-28).

export const GAME_DAY_SECONDS = 2880; // 48 минут реального времени (допущение П4 плана)
export const GAME_TZ = 'Asia/Omsk';

export function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

/** Уровень энергии: ниже 20% «мало», ниже 10% «критично». */
export function energyLevel(energy, max) {
    if (!(max > 0)) return 'ok';
    const share = energy / max;
    if (share <= 0.1) return 'critical';
    if (share <= 0.2) return 'low';
    return 'ok';
}

/** Уровень груза: от 80% «тяжело», 100% «полный». */
export function packLevel(kg, capacity) {
    if (!(capacity > 0)) return 'ok';
    const share = kg / capacity;
    if (share >= 1) return 'full';
    if (share >= 0.8) return 'heavy';
    return 'ok';
}

/** Фаза суток φ от 0 до 1 по серверному времени в миллисекундах. */
export function dayPhase(serverMs, daySeconds = GAME_DAY_SECONDS) {
    const t = (serverMs / 1000) % daySeconds;
    return (t < 0 ? t + daySeconds : t) / daySeconds;
}

/** Ночь: φ < 0.175 или φ > 0.825 (35% суток). */
export function isNight(phase) {
    return phase < 0.175 || phase > 0.825;
}

export function phaseInfo(phase) {
    if (isNight(phase)) return { id: 'night', icon: '🌙', label: S.phase.night };
    if (phase < 0.25) return { id: 'dawn', icon: '🌅', label: S.phase.dawn };
    if (phase < 0.75) return { id: 'day', icon: '☀️', label: S.phase.day };
    return { id: 'dusk', icon: '🌇', label: S.phase.dusk };
}

const SEASON_ICONS = { winter: '❄️', spring: '🌷', summer: '🌿', autumn: '🍂' };

/** Сезон по реальной дате (месяц в часовом поясе игры): зима 12–2, весна 3–5, лето 6–8, осень 9–11. */
export function seasonOf(date = new Date(), timeZone = GAME_TZ) {
    let month;
    try {
        month = Number(new Intl.DateTimeFormat('en-US', { timeZone, month: 'numeric' }).format(date));
    } catch (e) {
        month = date.getUTCMonth() + 1; // запасной вариант без Intl: месяц по UTC
    }
    let id = 'autumn';
    if (month === 12 || month <= 2) id = 'winter';
    else if (month <= 5) id = 'spring';
    else if (month <= 8) id = 'summer';
    return { id, icon: SEASON_ICONS[id], label: S.season[id] };
}

const WEATHER_ICONS = { clear: '☀️', cloudy: '☁️', rain: '🌧️', storm: '⛈️', fog: '🌫️', snow: '🌨️', blizzard: '❄️' };

export function weatherInfo(id) {
    if (!id || !S.weather[id]) return null;
    return { id, icon: WEATHER_ICONS[id], label: S.weather[id] };
}

/** Монеты: тысячи отделяются узким пробелом, от миллиона «1,2 млн». */
export function formatCoins(value) {
    const n = Math.max(0, Math.floor(Number(value) || 0));
    if (n >= 1000000) return `${(Math.floor(n / 100000) / 10).toString().replace('.', ',')} млн`;
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f');
}

/** Килограммы с одним знаком после запятой, лишний ноль не пишем. */
export function formatKg(value) {
    const n = Math.round((Number(value) || 0) * 10) / 10;
    return String(n).replace('.', ',');
}

/** Имя не длиннее max символов: лишнее заменяется многоточием, по границе слова, если получается. */
export function truncateName(name, max = 24) {
    const text = String(name || '').trim();
    const chars = Array.from(text);
    if (chars.length <= max) return text;
    const cut = chars.slice(0, max - 1).join('');
    const space = cut.lastIndexOf(' ');
    const base = space >= Math.floor(max / 2) ? cut.slice(0, space) : cut;
    return `${base.trimEnd()}…`;
}

/** Масштаб полосы энергии от 0 до 1. */
export function barShare(value, max) {
    if (!(max > 0)) return 0;
    return clamp(value / max, 0, 1);
}
