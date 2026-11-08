import { benchRows, gearOfLevels } from '../shared/upgrades.js';

// Верстак (ДЛ-18): окно улучшений бобра, действие upgrade_buy, разбор ответа сервера.
// Уровни, снаряжение и быстрые слоты хранятся здесь; изменения уходят в остальной мир через onChange.
export function createBenchFlow({ world, ui, notify, text, icons = {}, getQueue, getSync, getHave = () => ({}), getCoins = () => 0, isSleeping = () => false, onChange = () => {}, slots = 6 }) {
    let catalog = null;
    let levels = { axe: 1, pack: 1, stamina: 1, lamp: 0 };
    let gear = {};
    let hotbar = ['axe'];

    const heroXZ = () => { const p = world.heroPos; return { px: Math.round(p.x * 100) / 100, pz: Math.round(p.z * 100) / 100 }; };
    const rows = () => benchRows({ catalog, levels, gear, hotbar, have: getHave(), coins: getCoins(), slots });
    const emit = () => onChange({ levels: { ...levels }, gear: { ...gear }, hotbar: [...hotbar], beaverGear: gearOfLevels(levels) });

    function open() {
        if (!catalog || !catalog.levels) { notify({ text: text.errors.bad_state }); return; }
        ui.open(rows(), icons);
    }
    function buy(id, to) {
        if (isSleeping()) { notify({ text: text.errors.sleeping }); return; }
        const q = getQueue();
        if (!q) { notify({ text: text.errors.bad_state }); return; }
        q.push('upgrade_buy', { kind: id, ...(to ? { to } : {}), ...heroXZ() });
        const sync = getSync();
        if (sync && sync.flushSoon) sync.flushSoon(); else if (sync && sync.flush) sync.flush();
    }
    function onResult(r) {
        if (r.ok) {
            if (r.upgrades) levels = { ...levels, ...r.upgrades };
            if (r.gear) gear = { ...r.gear };
            if (Array.isArray(r.hotbar)) hotbar = r.hotbar.map(String);
            emit();
            notify({ text: text.done(text.names[r.kind] ? text.names[r.kind].name : r.kind) });
        } else {
            if (r.upgrades) { levels = { ...levels, ...r.upgrades }; emit(); }
            notify({ text: text.errors[r.error] || text.errors.bad_state });
        }
        ui.refresh(rows(), icons);
    }

    return {
        init(full) {
            if (!full) return;
            catalog = full.upgrade_catalog || null;
            if (full.upgrades) levels = { ...levels, ...full.upgrades };
            gear = full.gear && typeof full.gear === 'object' ? { ...full.gear } : {};
            if (Array.isArray(full.hotbar) && full.hotbar.length) hotbar = full.hotbar.map(String);
            emit();
        },
        open, buy,
        refresh() { if (ui.isOpen) ui.refresh(rows(), icons); },
        get levels() { return levels; },
        get gear() { return gear; },
        get hotbar() { return hotbar; },
        onResults(results) { for (const r of results) { if (!r.dup && r.type === 'upgrade_buy') onResult(r); } },
    };
}
