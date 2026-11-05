// Связка построек (ДЛ-16): каталог, выбор места призраком, постановка чертежа, стройка тапами, отмена и снос.
// ДЛ-17: меню готовой постройки (склад, дом, рынок, мастерская), улучшение уровней тапами, перенос предметов на склад, отдых у дома.
// Клиент предлагает, сервер решает: действия build_place, build_tap, build_cancel и build_demolish уходят в очередь sync,
// настоящее состояние (прогресс, ресурсы, монеты) приходит в ответе. Тапы стройки копятся пачкой и показываются сразу.
// Все зависимости передаются снаружи, поэтому логика проверяется в node без браузера.
import { BUILD_CATALOG, BUILD_RULES, BUILD_UPGRADES, buildCost, buildCoins, findSpec, houseBeds, maxLevel, normRot, refundK, refund, shortage, upgradeSpec, warehouseCap } from '../shared/build.js';
import { itemKg } from '../shared/formulas.js';
import { checkSpot } from './build-check.js';

const MIN_FLUSH_GAP_MS = 3200; // сервер принимает sync не чаще раза в 3 секунды
const BATCH_MS = 900;          // тапы стройки уходят пачкой раз в это время
const BATCH_MAX = 25;

/** Круги объектов поляны, мешающие стройке (как на сервере): ящик, подвода, пруд, плотина. */
export function fixedCircles(start) {
    const out = [];
    const v = start && start.village;
    if (v && v.crate) out.push([v.crate.x, v.crate.z, 0.9]);
    if (v && v.buyer) out.push([v.buyer.x, v.buyer.z, 1.6]);
    const p = start && start.pond;
    if (p) {
        out.push([p.x, p.z, p.r + 0.6]);
        if (p.dam) out.push([p.dam.x, p.dam.z, 2.4]);
    }
    return out;
}

export function createBuildFlow({ world, buildUi, dialog, notify, text, names = {}, village = null, fixed = [], getQueue, getSync, menuUi = null, storeUi = null, inventory = null, getPack = () => ({}), getEnergyMax = () => null, getHave = () => ({}), getCoins = () => 0, getEnergy = () => null, isSleeping = () => false, onStaged = () => {}, now = () => Date.now(), setTimer = setTimeout, clearTimer = clearTimeout }) {
    let catalog = BUILD_CATALOG;
    let rules = BUILD_RULES;
    let level = 1;
    let placing = null;      // { type, spec, x, z, rot, ok, reason, sending }
    let cur = null;          // { id } — постройка, с которой работает бобёр
    const queued = new Map();   // id → тапов, ещё не отправленных
    const inflight = [];        // { id, n } — отправленные, но не подтверждённые пачки
    let batchTimer = 0;
    let lastFlush = 0;
    let flushTimer = 0;
    let warnedOffline = false;
    let confirming = false;
    let upgrades = BUILD_UPGRADES;   // цены улучшений уровней
    let stores = {};                 // содержимое складов по id постройки (по данным сервера)
    let storeId = null;              // какой склад открыт сейчас
    let restId = null;               // у какого дома бобёр отдыхает

    const nameOf = (item) => names[item] || item;
    const meta = (type) => text.names[type] || { name: type, icon: '🏠', about: '' };

    function requestFlush() {
        const sync = getSync();
        if (!sync || !sync.running) return;
        clearTimer(flushTimer);
        const wait = Math.max(0, lastFlush + MIN_FLUSH_GAP_MS - now());
        if (wait === 0) { lastFlush = now(); sync.flush(); } else flushTimer = setTimer(() => { lastFlush = now(); sync.flush(); }, wait);
    }
    function queueOrWarn() {
        const q = getQueue();
        if (!q && !warnedOffline) { warnedOffline = true; notify({ text: text.errors.offline }); }
        return q;
    }
    const heroXZ = () => { const p = world.heroPos; return { px: Math.round(p.x * 100) / 100, pz: Math.round(p.z * 100) / 100 }; };
    const mine = () => world.buildings.list().filter((b) => b.mine);
    const counts = (type) => { const m = mine(); return { total: m.length, same: m.filter((b) => b.type === type).length }; };
    const pending = (id) => (queued.get(id) || 0) + inflight.filter((x) => x.id === id).reduce((a, x) => a + x.n, 0);
    const pctOf = (b, shown) => Math.min(100, Math.floor((shown / Math.max(1, b.needed)) * 100));

    // ── Меню готовой постройки (ДЛ-17) ───────────────────────────────────
    const units = (m) => Object.values(m || {}).reduce((a, b) => a + (Number(b) || 0), 0);
    const storeBox = (id) => stores[String(id)] || {};
    const storeCap = (b) => Number(b.cap) > 0 ? Number(b.cap) : warehouseCap(b.level || 1);
    const listOf = (m) => Object.keys(m || {}).filter((k) => m[k] > 0).sort().map((key) => ({ key, qty: m[key] }));

    function upgradeModel(b) {
        const spec = findSpec(catalog, b.type);
        const max = Number(b.max_level) || maxLevel(spec);
        if (max <= 1) return null;
        const to = (b.level || 1) + 1;
        if (to > max) return { to, status: 'max', cost: [], taps: 0 };
        const up = upgradeSpec(upgrades, b.type, to);
        if (!up) return null;
        const have = getHave();
        const coins = getCoins();
        const lack = shortage(up, have, coins);
        const cost = Object.entries(buildCost(up)).map(([item, qty]) => ({ icon: (text.itemIcons && text.itemIcons[item]) || '', qty, have: have[item] || 0, lack: lack[item] || 0 }));
        if (buildCoins(up) > 0) cost.push({ icon: '🪙', qty: buildCoins(up), have: coins, lack: lack.coins || 0 });
        const lackText = Object.keys(lack).map((k) => (k === 'coins' ? `${lack[k]} ${text.coins}` : `${nameOf(k).toLowerCase()} ${lack[k]}`)).join(', ');
        return { to, status: Object.keys(lack).length ? 'lack' : 'ok', cost, taps: up.taps, lackText, note: text.menu.upgradeNote };
    }
    function menuModel(b) {
        const m = meta(b.type);
        const M = text.menu;
        const spec = findSpec(catalog, b.type);
        const lines = [];
        const actions = [];
        if (b.type === 'warehouse') {
            lines.push(...M.lines.warehouse(storeCap(b), units(storeBox(b.id))));
            actions.push({ id: 'open', label: `📦 ${M.actions.open}` });
        } else if (b.type === 'house') {
            lines.push(...M.lines.house(Number(b.beds) || houseBeds(b.level || 1)));
            actions.push({ id: 'sleep', label: `🛏️ ${M.actions.sleep}` });
        } else if (b.type === 'market') {
            lines.push(...M.lines.market(Number(b.places) || 3));
        } else if (b.type === 'workshop') {
            lines.push(...M.lines.workshop());
            actions.push({ id: 'bench', label: `🔨 ${M.actions.bench}` });
        } else if (m.about) lines.push(m.about);
        return { id: b.id, name: m.name, icon: m.icon, level: b.level || 1, maxLevel: Number(b.max_level) || maxLevel(spec), lines, actions, upgrade: upgradeModel(b) };
    }
    function openMenu(b) {
        if (!menuUi) { cur = { id: b.id }; paintPanel(); return; }
        menuUi.open(menuModel(b));
    }
    function refreshMenu() {
        if (!menuUi || !menuUi.isOpen) return;
        const b = world.buildings.get(menuUi.currentId);
        if (b) menuUi.refresh(menuModel(b)); else menuUi.close();
    }
    function startUpgrade(id) {
        const b = world.buildings.get(id);
        if (!b || !b.mine || b.state !== 'ready') return;
        if (isSleeping()) { notify({ text: text.errors.sleeping }); return; }
        const q = queueOrWarn();
        if (!q) return;
        q.push('build_upgrade', { building: id, ...heroXZ() });
        requestFlush();
    }
    function nearHero(b, extra) {
        const r = (findSpec(catalog, b.type) || { r: 2.8 }).r + extra;
        const p = world.heroPos;
        return Math.hypot(p.x - b.x, p.z - b.z) <= r;
    }

    // ── Склад ───────────────────────────────────────────────────────────
    function paintStore() {
        if (!storeUi || storeId === null) return;
        const b = world.buildings.get(storeId);
        if (!b) { storeUi.close(); storeId = null; return; }
        storeUi.set({ pack: listOf(getPack()), box: listOf(storeBox(storeId)), used: units(storeBox(storeId)), cap: storeCap(b) });
    }
    function openStore(b) {
        if (!storeUi) return;
        if (isSleeping()) { notify({ text: text.store.sleeping }); return; }
        if (!getQueue()) { notify({ text: text.store.offline }); return; }
        storeId = b.id;
        storeUi.setTitle(`${text.store.title}`, meta('warehouse').icon);
        paintStore();
        storeUi.open();
    }
    function moveStore({ from, item, qty }) {
        if (storeId === null) return false;
        const b = world.buildings.get(storeId);
        if (!b) return false;
        if (isSleeping()) { notify({ text: text.store.sleeping }); return false; }
        const q = queueOrWarn();
        if (!q) return false;
        const pack = getPack();
        const box = storeBox(storeId);
        const src = from === 'pack' ? pack : box;
        let n = Math.min(qty, src[item] || 0);
        if (from === 'pack') n = Math.min(n, Math.max(0, storeCap(b) - units(box)));
        if (n <= 0) { notify({ text: from === 'pack' ? text.store.noSpaceBox : text.store.noItems }); return false; }
        src[item] = (src[item] || 0) - n;
        const dst = from === 'pack' ? (stores[String(storeId)] = stores[String(storeId)] || {}) : pack;
        dst[item] = (dst[item] || 0) + n;
        if (inventory) { if (from === 'pack') inventory.remove(item, n); else inventory.add(item, n, itemKg(item)); }
        paintStore();
        q.push(from === 'pack' ? 'wh_put' : 'wh_take', { building: storeId, item, qty: n, ...heroXZ() });
        requestFlush();
        return true;
    }

    // ── Дом: отдых ──────────────────────────────────────────────────────
    function stopRest(announce) {
        if (restId === null) return;
        restId = null;
        world.releaseBeaver();
        if (announce) notify({ text: text.menu.rest.end });
    }
    function restAtHome(b) {
        if (isSleeping()) { notify({ text: text.errors.sleeping }); return; }
        if (!nearHero(b, 3.2)) { notify({ text: text.menu.rest.far }); return; }
        const e = getEnergy();
        const max = getEnergyMax();
        if (e !== null && max !== null && e >= max - 0.5) { notify({ text: text.menu.rest.full }); return; }
        world.ctl.cancelWork();
        restId = b.id;
        world.playBeaver('sleep');
        notify({ text: text.menu.rest.start });
    }
    function onAction(act, id) {
        const b = world.buildings.get(id);
        if (!b || !b.mine) return;
        if (act === 'open') { menuUi.close(); openStore(b); } else if (act === 'sleep') { menuUi.close(); restAtHome(b); } else if (act === 'bench') {
            menuUi.close();
            dialog.say({ speaker: text.menu.bench.title, portrait: meta('workshop').icon, text: `${text.menu.bench.about}\n${text.menu.bench.list.join('\n')}`, choices: [{ id: 'ok', label: text.menu.close }] });
        }
    }

    // ── Каталог ─────────────────────────────────────────────────────────
    function catalogModel() {
        const have = getHave();
        const coins = getCoins();
        const items = catalog.map((spec) => {
            const m = meta(spec.type);
            const c = counts(spec.type);
            const lack = shortage(spec, have, coins);
            const cost = Object.entries(buildCost(spec)).map(([item, qty]) => ({ icon: (text.itemIcons && text.itemIcons[item]) || '', qty, have: have[item] || 0, lack: lack[item] || 0 }));
            if (buildCoins(spec) > 0) cost.push({ icon: '🪙', qty: buildCoins(spec), have: coins, lack: lack.coins || 0 });
            let status = 'ok';
            if (level < spec.unlock) status = 'locked';
            else if (c.same >= spec.max) status = 'maxed';
            else if (c.total >= rules.max_total) status = 'limit';
            else if (Object.keys(lack).length) status = 'lack';
            const lackText = Object.keys(lack).map((k) => (k === 'coins' ? `${lack[k]} ${text.coins}` : `${nameOf(k).toLowerCase()} ${lack[k]}`)).join(', ');
            return { type: spec.type, name: m.name, icon: m.icon, about: m.about, cost, taps: spec.taps, unlock: spec.unlock, status, lackText };
        });
        return { items };
    }

    // ── Выбор места ─────────────────────────────────────────────────────
    function spotDeps() {
        return {
            rules, level, village: village || { x: 0, z: 0 },
            buildings: world.buildings.circles(), fixed,
            heightAt: (x, z) => world.groundY(x, z), isWater: (x, z) => world.isWater(x, z),
            nearObstacles: (x, z, range, cb) => world.chunks.forEachNear(x, z, range, cb),
        };
    }
    function recheck() {
        if (!placing) return;
        const r = checkSpot(placing.spec, placing.x, placing.z, counts(placing.type), spotDeps());
        placing.ok = r.ok;
        placing.reason = r.reason;
        world.buildings.showGhost(placing.type, placing.x, placing.z, placing.rot, r.ok ? 'ok' : 'bad');
        buildUi.showPlacing({ valid: r.ok && !placing.sending, text: r.ok ? text.okSpot : (text.reasons[r.reason] || text.errors.bad_place) });
    }
    function startPlacement(type) {
        const spec = findSpec(catalog, type);
        if (!spec) return;
        const lack = shortage(spec, getHave(), getCoins());
        if (Object.keys(lack).length) notify({ text: `${text.lack}: ${Object.keys(lack).map((k) => (k === 'coins' ? `${lack[k]} ${text.coins}` : `${nameOf(k).toLowerCase()} ${lack[k]}`)).join(', ')}` });
        const p = world.heroPos;
        const yaw = world.cameraYaw;
        const d = spec.r + 3;
        placing = { type, spec, x: Math.round((p.x - Math.sin(yaw) * d) * 10) / 10, z: Math.round((p.z - Math.cos(yaw) * d) * 10) / 10, rot: 0, ok: false, reason: null, sending: false };
        world.ctl.cancelWork();
        stopWork();
        notify({ text: text.placing(meta(type).name), ttlMs: 2500 });
        recheck();
    }
    function endPlacement() {
        placing = null;
        world.buildings.hideGhost();
        buildUi.hidePlacing();
    }
    function confirmPlacement() {
        if (!placing || placing.sending || !placing.ok) return;
        if (isSleeping()) { notify({ text: text.errors.sleeping }); return; }
        const q = queueOrWarn();
        if (!q) return;
        placing.sending = true;
        recheck();
        q.push('build_place', { btype: placing.type, x: placing.x, z: placing.z, rot: placing.rot });
        requestFlush();
    }

    // ── Работа с постройкой ─────────────────────────────────────────────
    function paintPanel() {
        if (!cur) { buildUi.hidePanel(); return; }
        const b = world.buildings.get(cur.id);
        if (!b) { buildUi.hidePanel(); return; }
        const m = meta(b.type);
        const shown = b.state === 'ready' ? b.needed : Math.min(b.needed, b.progress + pending(b.id));
        buildUi.showPanel({ id: b.id, name: m.name, icon: m.icon, pct: pctOf(b, shown), ready: b.state === 'ready', hideAct: b.state === 'upgrading', hint: b.state === 'ready' ? '' : text.keepTapping });
    }
    function stopWork() {
        if (cur) flushTaps();
        cur = null;
        buildUi.hidePanel();
    }
    function flushTaps() {
        clearTimer(batchTimer);
        batchTimer = 0;
        for (const [id, n] of Array.from(queued.entries())) {
            if (n <= 0) continue;
            const q = getQueue();
            if (!q) { queued.delete(id); continue; }
            q.push('build_tap', { building: id, taps: n, ...heroXZ() });
            inflight.push({ id, n });
            queued.delete(id);
            requestFlush();
        }
    }
    function addTap(b) {
        const energy = getEnergy();
        const cost = rules.tap_cost;
        if (energy !== null && energy - pending(b.id) * cost + 1e-9 < cost) { world.ctl.cancelWork(); notify({ text: text.errors.no_energy }); stopWork(); return; }
        if (pending(b.id) + b.progress >= b.needed) return; // дальше ждём подтверждения сервера
        if (!queueOrWarn()) { world.ctl.cancelWork(); return; }
        queued.set(b.id, (queued.get(b.id) || 0) + 1);
        world.buildings.tap(b.id);
        world.buildings.showProgress(b.id, Math.min(b.needed, b.progress + pending(b.id)));
        paintPanel();
        if ((queued.get(b.id) || 0) >= BATCH_MAX) flushTaps();
        else if (!batchTimer) batchTimer = setTimer(() => { batchTimer = 0; flushTaps(); }, BATCH_MS);
    }
    function refundText(items, coins) {
        const parts = Object.entries(items || {}).map(([k, n]) => `${nameOf(k).toLowerCase()} × ${n}`);
        if (coins > 0) parts.push(`${coins} ${text.coins}`);
        return parts.join(', ');
    }
    async function askRemove(id, demolish) {
        const b = world.buildings.get(id);
        if (!b || confirming) return;
        const spec = findSpec(catalog, b.type);
        const k = refundK(b.state, b.progress, b.needed, rules);
        if (k === null) return;
        confirming = true;
        try {
            const pct = Math.round(k * 100);
            const r = await dialog.say({ speaker: meta(b.type).name, portrait: meta(b.type).icon, text: demolish ? text.confirmDemolish(pct) : text.confirmCancel(pct), choices: [{ id: 'yes', label: demolish ? text.demolish : text.cancel }, { id: 'no', label: text.keep }] });
            if (r.id !== 'yes') return;
            const q = queueOrWarn();
            if (!q) return;
            queued.delete(id);
            q.push(demolish ? 'build_demolish' : 'build_cancel', { building: id });
            requestFlush();
            void spec;
        } finally {
            confirming = false;
        }
    }

    function onPlaceResult(r) {
        if (!r.ok) {
            if (placing) { placing.sending = false; recheck(); }
            const code = r.error || 'failed';
            if (code === 'bad_place') notify({ text: text.reasons[r.reason] || text.errors.bad_place });
            else if (code === 'no_items' && r.need) notify({ text: `${text.errors.no_items} ${refundText(r.need, 0)}` });
            else notify({ text: text.errors[code] || text.errors.failed });
            return;
        }
        endPlacement();
        world.buildings.upsert(r.building);
        notify({ text: text.placed(meta(r.building.type).name) });
        onStaged(r.building);
        refreshCatalog();
    }
    function onTapResult(r) {
        const bid = r.building_id || (r.building && r.building.id);
        const i = inflight.findIndex((x) => x.id === bid);
        const batch = i >= 0 ? inflight.splice(i, 1)[0] : null;
        if (r.building) world.buildings.upsert(r.building);
        const b = world.buildings.get(bid);
        if (b) world.buildings.showProgress(bid, b.state === 'ready' ? b.needed : Math.min(b.needed, b.progress + pending(bid)));
        if (!r.ok) {
            const code = r.error || 'failed';
            if (code === 'no_building') world.buildings.remove(bid);
            if (code === 'too_far' || code === 'no_energy' || code === 'sleeping') { world.ctl.cancelWork(); if (cur && cur.id === bid) stopWork(); }
            notify({ text: text.errors[code] || text.errors.failed });
        } else {
            if (batch && r.accepted < batch.n) notify({ text: r.limit === 'energy' ? text.errors.no_energy : text.errors.too_fast });
            if (r.building && r.building.state === 'ready') {
                notify({ text: (r.upgraded ? text.menu.upgraded(meta(r.building.type).name, r.building.level) : text.done(meta(r.building.type).name)) + (r.xp ? ` ${text.xp(r.xp)}` : '') });
                if (r.migrated && Object.keys(r.migrated).length) notify({ text: text.store.migrated });
                if (cur && cur.id === bid) { world.ctl.cancelWork(); cur = null; buildUi.hidePanel(); }
            }
        }
        paintPanel();
        refreshCatalog();
    }
    function onRemoveResult(r) {
        const bid = r.building_id;
        if (!r.ok) {
            if (r.building) world.buildings.upsert(r.building);
            if (r.error === 'no_building') world.buildings.remove(bid);
            notify({ text: text.errors[r.error] || text.errors.failed });
            return;
        }
        world.buildings.remove(r.removed);
        if (cur && cur.id === r.removed) { cur = null; buildUi.hidePanel(); world.ctl.cancelWork(); }
        const items = r.refund && r.refund.items ? r.refund.items : {};
        const rt = refundText(items, r.refund ? r.refund.coins : 0);
        notify({ text: text.removed + (rt ? ` ${text.refunded(rt)}` : '') });
        if (r.refund && r.refund.dropped && Object.keys(r.refund.dropped).length) notify({ text: text.refundDropped });
        refreshCatalog();
    }
    function onUpgradeResult(r) {
        if (!r.ok) {
            if (r.building) world.buildings.upsert(r.building);
            if (r.error === 'no_building') world.buildings.remove(r.building_id);
            const code = r.error || 'failed';
            if (code === 'no_items' && r.need) notify({ text: `${text.errors.no_items} ${refundText(r.need, 0)}` });
            else notify({ text: text.menu.errors[code] || text.errors[code] || text.errors.failed });
            return;
        }
        world.buildings.upsert(r.building);
        notify({ text: text.menu.started(meta(r.building.type).name, r.to_level) });
        cur = { id: r.building.id };
        paintPanel();
        refreshCatalog();
    }
    function onStoreResult(r) {
        if (r.ok) {
            if (r.left > 0) notify({ text: r.type === 'wh_put' ? text.store.partialPut : text.store.partialTake });
            return;
        }
        const put = r.type === 'wh_put';
        const code = r.error || 'failed';
        notify({ text: code === 'no_space' ? (put ? text.store.noSpaceBox : text.store.noSpacePack) : code === 'no_items' ? text.store.noItems : code === 'too_far' ? text.store.far : code === 'sleeping' ? text.store.sleeping : text.store.failed });
    }
    function refreshCatalog() { if (buildUi.catalogOpen) buildUi.refreshCatalog(catalogModel()); refreshMenu(); }

    return {
        get placing() { return Boolean(placing); },
        get level() { return level; },
        get stores() { return stores; },
        /** Каталог, правила и постройки из полного состояния сервера. */
        init({ catalog: c, rules: r, buildings: list, level: lv, upgrades: up } = {}) {
            if (up && typeof up === 'object') upgrades = up;
            if (Array.isArray(c) && c.length) catalog = c;
            if (r && typeof r === 'object') rules = { ...BUILD_RULES, ...r };
            if (Number.isFinite(Number(lv))) level = Number(lv);
            world.buildings.setCatalog(catalog);
            world.buildings.set(Array.isArray(list) ? list : []);
        },
        onState(st) {
            if (st && Number.isFinite(Number(st.level))) level = Number(st.level);
            if (st && st.stores && typeof st.stores === 'object') { stores = {}; for (const [id, m] of Object.entries(st.stores)) stores[id] = { ...m }; paintStore(); }
            refreshCatalog();
        },
        moveStore,
        startUpgrade,
        onMenuAction: onAction,
        openCatalog() { if (isSleeping()) { notify({ text: text.errors.sleeping }); return; } buildUi.openCatalog(catalogModel()); },
        pick: startPlacement,
        rotate(dir) { if (!placing) return; placing.rot = normRot(placing.rot + dir * rules.rot_step, rules.rot_step); recheck(); },
        confirm: confirmPlacement,
        cancelPlacement: endPlacement,
        askCancel: (id) => askRemove(id, false),
        askDemolish: (id) => askRemove(id, true),
        /** Тап по экрану в режиме выбора места: двигает призрак. Возвращает true, если тап съеден. */
        onScreenTap(px, py) {
            if (restId !== null) stopRest(true);
            if (!placing) return false;
            const hit = world.groundFromScreen(px, py);
            if (hit) { placing.x = Math.round(hit.x * 10) / 10; placing.z = Math.round(hit.z * 10) / 10; recheck(); }
            return true;
        },
        /** Событие сцены: бобёр дошёл до постройки (ready) или тапнул по ней (workTap). */
        onWorldEvent(e) {
            const o = e.obj;
            if (!o || o.kind !== 'building') return;
            const b = world.buildings.get(o.buildingId);
            if (!b) return;
            if (e.type === 'ready') {
                if (!b.mine) { world.ctl.cancelWork(); return; }
                if (isSleeping()) { world.ctl.cancelWork(); notify({ text: text.errors.sleeping }); return; }
                if (b.state === 'ready' && menuUi) { world.ctl.cancelWork(); openMenu(b); return; }
                cur = { id: b.id };
                paintPanel();
            } else if (e.type === 'workTap') {
                if (!b.mine || b.state === 'ready') return;
                if (!cur || cur.id !== b.id) cur = { id: b.id };
                addTap(b);
            }
        },
        /** Каждый кадр: ушёл от постройки, панель закрывается, оставшиеся тапы уходят. */
        update() {
            if (restId !== null && world.ctl.mode !== 'idle') stopRest(true);
            if (!cur) return;
            const m = world.ctl.mode;
            if (m !== 'work' && m !== 'turn' && m !== 'approach') stopWork();
        },
        onResults(results) {
            for (const r of results) {
                if (r.dup) continue;
                if (r.type === 'build_place') onPlaceResult(r);
                else if (r.type === 'build_tap') onTapResult(r);
                else if (r.type === 'build_cancel' || r.type === 'build_demolish') onRemoveResult(r);
                else if (r.type === 'build_upgrade') onUpgradeResult(r);
                else if (r.type === 'wh_put' || r.type === 'wh_take') onStoreResult(r);
            }
        },
        dispose() { clearTimer(flushTimer); clearTimer(batchTimer); stopRest(false); },
    };
}
