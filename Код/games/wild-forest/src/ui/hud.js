import { S } from './strings.ru.js';
import {
    energyLevel, packLevel, dayPhase, phaseInfo, seasonOf, weatherInfo,
    formatCoins, formatKg, barShare,
} from './hud-logic.js';

const HOTBAR_SLOTS = 6;
const SEL_KEY = 'wf.hotbar.sel';

// HUD поверх холста (ДЛ-09): энергия, рюкзак, монеты, часы суток, сезон и погода, быстрые кнопки, хотбар на 6 слотов.
// DOM обновляется только при смене значений. Сервер придёт в ДЛ-10: он будет вызывать set(), а HUD ничего не считает сам.
export function createHud({ onAction = () => {}, onSlot = () => {}, serverNow = () => Date.now() } = {}) {
    const $ = (id) => document.getElementById(id);
    const root = $('wfHud');
    const quick = $('wfQuick');
    const hotbar = $('wfHotbar');
    const els = {
        energy: $('wfEnergy'), energyFill: $('wfEnergyFill'), energyText: $('wfEnergyText'), energyIcon: $('wfEnergyIcon'),
        pack: $('wfChipPack'), packIcon: $('wfPackIcon'), packText: $('wfPackText'),
        coins: $('wfCoinsText'),
        clockIcon: $('wfClockIcon'), clockText: $('wfClockText'),
        seasonIcon: $('wfSeasonIcon'), seasonText: $('wfSeasonText'),
        weather: $('wfChipWeather'), weatherIcon: $('wfWeatherIcon'), weatherText: $('wfWeatherText'),
    };

    // Последние показанные значения: сравниваем, прежде чем трогать DOM.
    const shown = {};
    function put(key, value, apply) {
        if (shown[key] === value) return false;
        shown[key] = value;
        apply(value);
        return true;
    }

    const state = { energy: 100, energyMax: 100, sleeping: false, packKg: 0, packCap: 40, coins: 0, weather: null };
    const warned = { energy: 'ok', pack: 'ok' };
    let warnHandler = () => {};
    let selected = 0;
    let slots = new Array(HOTBAR_SLOTS).fill(null);
    let lastClock = -1;

    try {
        const saved = Number(localStorage.getItem(SEL_KEY));
        if (Number.isInteger(saved) && saved >= 0 && saved < HOTBAR_SLOTS) selected = saved;
    } catch (e) { /* без хранилища выбран первый слот */ }

    function paintEnergy() {
        const level = energyLevel(state.energy, state.energyMax);
        const share = barShare(state.energy, state.energyMax);
        put('energy.text', `${Math.round(state.energy)} / ${Math.round(state.energyMax)}`, (v) => {
            els.energyText.textContent = v;
            els.energy.setAttribute('aria-valuenow', String(Math.round(state.energy)));
            els.energy.setAttribute('aria-valuemax', String(Math.round(state.energyMax)));
        });
        put('energy.width', Math.round(share * 1000), (v) => { els.energyFill.style.width = `${v / 10}%`; });
        put('energy.level', level, (v) => {
            els.energy.dataset.level = v;
        });
        put('energy.icon', state.sleeping ? 'sleep' : level, (v) => {
            els.energy.dataset.sleeping = v === 'sleep' ? '1' : '0';
            els.energyIcon.textContent = v === 'sleep' ? '😴' : v === 'critical' ? '💤' : v === 'low' ? '🪫' : '⚡';
        });
        if (warned.energy !== level) {
            warned.energy = level;
            if (level === 'low') warnHandler({ key: 'energy', level: 'warn', text: S.energy.low, sticky: false });
            else if (level === 'critical') warnHandler({ key: 'energy', level: 'danger', text: S.energy.critical, sticky: false });
        }
    }

    function paintPack() {
        const level = packLevel(state.packKg, state.packCap);
        put('pack.text', `${formatKg(state.packKg)} / ${formatKg(state.packCap)} ${S.hud.kg}`, (v) => { els.packText.textContent = v; });
        put('pack.level', level, (v) => {
            els.pack.dataset.level = v;
            els.packIcon.textContent = v === 'full' ? '⛔' : v === 'heavy' ? '🧺' : '🎒';
        });
        if (warned.pack !== level) {
            warned.pack = level;
            if (level === 'heavy') warnHandler({ key: 'pack', level: 'warn', text: S.pack.heavy });
            else if (level === 'full') warnHandler({ key: 'pack', level: 'warn', text: S.pack.full });
        }
    }

    function paintCoins() {
        put('coins', formatCoins(state.coins), (v) => { els.coins.textContent = v; });
    }

    function paintWeather() {
        const info = weatherInfo(state.weather);
        put('weather', info ? info.id : '', () => {
            els.weather.hidden = !info;
            if (info) {
                els.weatherIcon.textContent = info.icon;
                els.weatherText.textContent = info.label;
            }
        });
    }

    function paintClock() {
        const now = serverNow();
        const phase = dayPhase(now);
        const info = phaseInfo(phase);
        put('clock', info.id, () => {
            els.clockIcon.textContent = info.icon;
            els.clockText.textContent = info.label;
        });
        root.dataset.night = info.id === 'night' ? '1' : '0';
        const season = seasonOf(new Date(now));
        put('season', season.id, () => {
            els.seasonIcon.textContent = season.icon;
            els.seasonText.textContent = season.label;
        });
    }

    function paintHotbar() {
        slots.forEach((slot, i) => {
            const btn = hotbar.children[i];
            const label = slot ? slot.name : S.hud.slotEmpty;
            const sig = `${slot ? slot.key : ''}|${slot ? slot.icon : ''}|${slot && slot.badge ? slot.badge : ''}|${i === selected}`;
            put(`slot.${i}`, sig, () => {
                btn.textContent = '';
                btn.classList.toggle('is-empty', !slot);
                btn.setAttribute('aria-pressed', i === selected ? 'true' : 'false');
                btn.setAttribute('aria-label', `${i + 1}. ${label}`);
                const num = document.createElement('i');
                num.textContent = String(i + 1);
                btn.appendChild(num);
                if (slot) {
                    const icon = document.createElement('span');
                    icon.textContent = slot.icon;
                    btn.appendChild(icon);
                    if (slot.badge) {
                        const badge = document.createElement('b');
                        badge.textContent = slot.badge;
                        btn.appendChild(badge);
                    }
                }
            });
        });
    }

    function select(i, { silent = false } = {}) {
        if (i < 0 || i >= HOTBAR_SLOTS) return;
        selected = i;
        try { localStorage.setItem(SEL_KEY, String(i)); } catch (e) { /* не страшно */ }
        paintHotbar();
        if (!silent) onSlot(i, slots[i]);
    }

    // Кнопки хотбара создаются один раз.
    for (let i = 0; i < HOTBAR_SLOTS; i += 1) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'wf-slot';
        btn.dataset.slot = String(i);
        btn.addEventListener('click', () => select(i));
        hotbar.appendChild(btn);
    }
    quick.addEventListener('click', (event) => {
        const btn = event.target.closest('button[data-action]');
        if (btn) onAction(btn.dataset.action);
    });
    window.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey) return;
        const tag = event.target && event.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA') return;
        if (event.key >= '1' && event.key <= String(HOTBAR_SLOTS) && !root.hidden && !document.documentElement.dataset.wfModal) {
            select(Number(event.key) - 1);
        }
    });

    paintEnergy(); paintPack(); paintCoins(); paintWeather(); paintClock(); paintHotbar();

    return {
        show() { root.hidden = false; quick.hidden = false; hotbar.hidden = false; },
        hide() { root.hidden = true; quick.hidden = true; hotbar.hidden = true; },
        /** Обновить значения HUD (частично). Рисуется только то, что изменилось. */
        set(patch) {
            Object.assign(state, patch);
            paintEnergy(); paintPack(); paintCoins(); paintWeather();
        },
        /** Вызывается из главного цикла: часы и сезон перерисовываются не чаще раза в секунду. */
        tick(nowMs) {
            const sec = Math.floor(nowMs / 1000);
            if (sec === lastClock) return;
            lastClock = sec;
            paintClock();
        },
        setHotbar(next, sel) {
            slots = Array.from({ length: HOTBAR_SLOTS }, (_, i) => (next && next[i]) || null);
            if (Number.isInteger(sel)) selected = Math.min(HOTBAR_SLOTS - 1, Math.max(0, sel));
            paintHotbar();
        },
        select,
        get selected() { return selected; },
        get slots() { return slots.slice(); },
        get values() { return { ...state }; },
        /** Подписка на предупреждения (энергия, вес): уведомления показывает вызывающий код. */
        onWarn(handler) { warnHandler = handler; },
    };
}
