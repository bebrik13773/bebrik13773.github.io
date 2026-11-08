import { gardenRows, gardenSlots, seedsInPack, slotPos, leftText, seedSpecies } from '../shared/garden.js';
import { createSaplingView } from './sapling-view.js';

const CHOP_REACH = 3.5; // зеркало limits.chop_reach_m

// Поток сада (ДЛ-19): саженцы с сервера, их вид в мире, окно сада и действия plant, water, sapling_clear, sapling_chop.
// Рост и награды считает сервер; здесь показ, очередь действий и серия тапов по выросшему дереву.
export function createGardenFlow({ world, ui, notify, text, getQueue, getSync, getPack = () => ({}), getBuilding = () => null, isSleeping = () => false, now = () => Date.now() }) {
    let saplings = [];
    let recvAt = now();
    let openId = null;           // id сада, чьё окно открыто
    let chop = null;             // { id, hits, need, t0, t1 }
    const views = new Map();     // id саженца → вид

    const heroXZ = () => { const p = world.heroPos; return { px: Math.round(p.x * 100) / 100, pz: Math.round(p.z * 100) / 100 }; };
    const nameOf = (species) => (text.species[species] || species);

    function send(type, payload) {
        if (isSleeping()) { notify({ text: text.errors.sleeping }); return false; }
        const q = getQueue();
        if (!q) { notify({ text: text.errors.offline }); return false; }
        q.push(type, { ...payload, ...heroXZ() });
        const sync = getSync();
        if (sync && sync.flushSoon) sync.flushSoon(); else if (sync && sync.flush) sync.flush();
        return true;
    }

    function syncViews() {
        const ids = new Set(saplings.map((s) => s.id));
        for (const [id, v] of views) if (!ids.has(id)) { v.dispose(); views.delete(id); }
        for (const s of saplings) {
            const v = views.get(s.id);
            if (v) { v.update(s); continue; }
            const nv = createSaplingView(s);
            nv.group.position.set(s.x, world.groundY(s.x, s.z), s.z);
            world.addObject(nv.group);
            views.set(s.id, nv);
        }
    }

    function model() {
        const b = getBuilding(openId);
        const level = b ? b.level || 1 : 1;
        const age = (now() - recvAt) / 1000;
        const raw = gardenRows({ buildingId: openId, level, saplings, age });
        const T = text;
        const rows = raw.map((r) => {
            if (r.state === 'empty') return { slot: r.slot, icon: '🕳️', text: T.row.empty(r.slot + 1), actions: [{ act: 'plant', label: T.act.plant }] };
            const name = nameOf(r.species);
            if (r.state === 'dry') return { slot: r.slot, icon: '🥀', text: T.row.dry(name), sub: T.row.dryHint, actions: [{ act: 'clear', id: r.id, label: T.act.clear }] };
            if (r.state === 'grown') {
                return { slot: r.slot, icon: '🌳', text: T.row.grown(name), sub: r.big ? T.row.bigHint : null, bar: 1, actions: [{ act: 'chop', id: r.id, label: T.act.chop(r.need) }] };
            }
            return {
                slot: r.slot, icon: ['🌱', '🌿', '🪴', '🌲'][Math.min(3, r.stage)], text: T.row.growing(name, Math.round(r.frac * 100)),
                sub: T.row.left(leftText(r.leftS)) + (r.watered ? ` · ${T.row.watered}` : ''), bar: r.frac,
                actions: [{ act: 'water', id: r.id, label: r.watered ? T.act.waterDone : T.act.water, disabled: r.watered }],
            };
        });
        const pickSlot = ui.pickSlot;
        let seeds = null;
        if (pickSlot !== null && pickSlot !== undefined) {
            seeds = {
                title: T.seeds.title(pickSlot + 1), empty: T.seeds.empty, cancel: T.seeds.cancel,
                items: seedsInPack(getPack()).map((s) => ({ item: s.item, icon: '🌱', name: T.seeds.name(nameOf(s.species)), qty: s.qty, sub: T.seeds.days(s.species) })),
            };
        }
        return {
            title: `🌳 ${T.title} · ${T.slots(rows.length, gardenSlots(level))}`, about: T.about, rows, seeds,
            chop: chop ? { title: T.chop.title, hits: chop.hits, need: chop.need, tap: T.chop.tap, stop: T.chop.stop } : null,
        };
    }
    const refresh = () => { if (ui.isOpen) ui.refresh(model()); };
    ui.requestRefresh = refresh;

    function chopTap() {
        if (!chop) return;
        const t = now();
        if (chop.t0 === null) chop.t0 = t;
        chop.t1 = t;
        chop.hits += 1;
        if (world.beaver && world.beaver.swing) world.beaver.swing();
        if (chop.hits >= chop.need) {
            const c = chop;
            chop = null;
            send('sapling_chop', { sapling: c.id, hits: c.hits, t0: c.t0, t1: c.t1 });
        }
        refresh();
    }

    function upsert(s) {
        saplings = saplings.filter((x) => x.id !== s.id).concat([s]);
        recvAt = now();
    }

    function onResult(r) {
        if (r.ok) {
            if (r.sapling) upsert(r.sapling);
            if (r.type === 'plant') notify({ text: text.done.plant(nameOf(r.sapling ? r.sapling.species : seedSpecies(r.item) || '')) });
            else if (r.type === 'water') notify({ text: text.done.water });
            else if (r.type === 'sapling_clear') { saplings = saplings.filter((x) => x.id !== r.sapling_id); notify({ text: text.done.clear }); }
            else if (r.type === 'sapling_chop') {
                saplings = saplings.filter((x) => x.id !== r.sapling_id);
                if (r.drops && world.drops) world.drops.add(r.drops);
                const logs = r.reward && r.reward.log ? r.reward.log : 0;
                notify({ text: text.done.chop(logs) + (r.xp ? ` ${text.xp(r.xp)}` : '') });
            }
        } else {
            notify({ text: text.errors[r.error] || text.errors.failed });
        }
        syncViews();
        refresh();
    }

    return {
        /** Список саженцев с сервера (при входе и в каждом ответе sync). */
        setSaplings(list) {
            if (!Array.isArray(list)) return;
            saplings = list.map((s) => ({ ...s }));
            recvAt = now();
            syncViews();
            refresh();
        },
        open(b) { openId = b.id; chop = null; ui.open(model()); },
        plant(slot, item) { if (openId !== null) send('plant', { building: openId, slot, item }); },
        water(id) { send('water', { sapling: id }); },
        clear(id) { send('sapling_clear', { sapling: id }); },
        chopStart(id) {
            const s = saplings.find((x) => x.id === id);
            if (!s || s.state !== 'grown' || !(s.need > 0)) { notify({ text: text.errors.not_grown }); return; }
            const hp = world.heroPos;
            if (Math.hypot(hp.x - s.x, hp.z - s.z) > CHOP_REACH - 0.3) { notify({ text: text.errors.too_far_tree }); return; }
            chop = { id, hits: 0, need: s.need, t0: null, t1: null };
            refresh();
        },
        chopTap,
        chopStop() { chop = null; },
        refresh,
        get saplings() { return saplings; },
        get chopping() { return chop; },
        onResults(results) { for (const r of results) { if (!r.dup && (r.type === 'plant' || r.type === 'water' || r.type === 'sapling_clear' || r.type === 'sapling_chop')) onResult(r); } },
        dispose() { for (const v of views.values()) v.dispose(); views.clear(); },
    };
}
