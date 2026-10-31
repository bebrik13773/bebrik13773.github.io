import { STATES } from './config.js';
import { renderBuildId, startAutoUpdate } from './update.js';
import { createQualityController, lowerQuality, shouldSuggestLower, getPreset } from './core/quality.js';
import { createRendererHost, detectWebGL } from './core/renderer.js';
import { createLoop } from './core/loop.js';
import { createFpsMeter } from './core/fps.js';
import { createWorldScene } from './world/scene.js';
import { loadWorldGen } from './world/gen.js';
import { installInput } from './core/input.js';
import { checkTrack } from './shared/formulas.js';
import { openSession, createActionQueue, createSync, apiCall, applyLightState, startFromState } from './net/index.js';
import { createLoadingScreen } from './ui/loading.js';
import { createNotice } from './ui/notice.js';
import { createErrorUi } from './ui/errors.js';
import { createSettingsUi } from './ui/settings.js';
import { createBetaNotice } from './ui/beta.js';
import { createDebugPanel, isDebugMode } from './ui/debug.js';
import { createHud } from './ui/hud.js';
import { createNotifyCenter } from './ui/notify-center.js';
import { createInventory } from './ui/inventory.js';
import { createDialog } from './ui/dialog.js';
import { createChopBar } from './ui/chop-bar.js';
import { createChopFlow } from './world/chop-flow.js';
import { createUprootFlow } from './world/uproot-flow.js';
import { createEnergyFlow } from './world/energy-flow.js';
import { createPackFlow } from './world/pack-flow.js';
import { createCrateFlow } from './world/crate-flow.js';
import { createCrateUi } from './ui/crate-ui.js';
import { S } from './ui/strings.ru.js';

window.__wfBooted = true;

let state = STATES.LOADING;
const setState = (next) => { state = next; document.documentElement.dataset.wfState = next; };
setState(STATES.LOADING);

const notice = createNotice();
const errors = createErrorUi({ notice, onFatal: () => { setState(STATES.ERROR); loop && loop.stop(); } });
errors.install();

const loading = createLoadingScreen();
const beta = createBetaNotice();
let loop = null;

// Версия билда и автообновление по SHA (ДЛ-02).
const buildEl = document.getElementById('wfBuild');
const statusEl = document.getElementById('wfStatus');
renderBuildId(buildEl, statusEl);
startAutoUpdate(buildEl, statusEl);

async function boot() {
    loading.step('code');

    if (!detectWebGL()) {
        loading.hide();
        errors.noWebGL();
        return;
    }

    const quality = createQualityController();
    const stage = document.getElementById('wfStage');
    const fps = createFpsMeter();
    let contextTimer = 0;
    let loseExt = null;
    let world = null;

    const host = createRendererHost({
        container: stage,
        onContextLost() {
            if (loop) loop.pause('context');
            notice.show({ text: 'Графика приостановлена, пробуем восстановить…', ttlMs: 0 });
            contextTimer = setTimeout(() => {
                errors.showFatal({
                    heading: 'Графика пропала',
                    message: 'Устройство не вернуло 3D-контекст. Перезагрузи страницу.',
                });
            }, 6000);
        },
        onContextRestored() {
            clearTimeout(contextTimer);
            notice.close();
            host.resize();
            if (loop) loop.resume('context');
        },
    });

    // Сессия с сервером идёт параллельно с загрузкой мира: health → state → join.
    const sessionPromise = openSession();
    let wg = null;
    try {
        wg = await loadWorldGen();
    } catch (error) {
        loading.hide();
        errors.showFatal({
            heading: 'Не загрузился мир',
            message: 'Не удалось загрузить данные леса. Проверь соединение и перезагрузи страницу.',
            technical: error && error.message ? error.message : String(error),
        });
        errors.report('worldgen', error);
        return;
    }

    let prevNetStatus = 'idle';
    const session = await sessionPromise;
    const online = session.mode === 'online';
    const start = online ? startFromState(session.full) : null;

    let chopFlow = null;
    let packFlow = null;
    let uprootFlow = null;
    let crateFlow = null;
    try {
        host.apply(quality.preset);
        world = createWorldScene({
            wg,
            start,
            onEvent(e) {
                if (chopFlow) chopFlow.onWorldEvent(e);
                if (packFlow) packFlow.onWorldEvent(e);
                if (crateFlow) crateFlow.onWorldEvent(e);
                if (uprootFlow) uprootFlow.onWorldEvent(e);
                if (e.type === 'blocked') notice.show({ text: 'Не получается пройти: что-то мешает. Попробуй другой путь.', ttlMs: 2200 });
                else if (e.type === 'unreachable') notice.show({ text: 'К этому не подобраться.', ttlMs: 1800 });
                else if (e.type === 'sleepingTap') notice.show({ text: S.energy.sleepingTap, ttlMs: 2200 });
            },
        });
        world.applyQuality(quality.preset);
        world.resize(window.innerWidth, window.innerHeight);
    } catch (error) {
        loading.hide();
        errors.showFatal({
            heading: 'Не удалось запустить 3D',
            message: 'Графика не запустилась на этом устройстве. Можно попробовать перезагрузить или открыть старый кликер.',
            technical: error && error.message ? error.message : String(error),
        });
        errors.report('init', error);
        return;
    }
    loading.step('world');

    // Единый слой ввода (ДЛ-08): слушает только холст, интерфейс поверх мира тапы не пропускает.
    installInput({
        host: stage,
        isActive: () => state === STATES.PLAYING && !document.documentElement.dataset.wfModal,
        onTap: (tap) => {
            const rect = stage.getBoundingClientRect();
            world.handleTap(tap.x - rect.left, tap.y - rect.top);
        },
        onOrbit: (dYaw, dPitch) => world.orbitCamera(dYaw, dPitch),
        onZoom: (factor) => world.zoomCamera(factor),
        onMoveKeys: (x, z) => world.setMoveInput(x, z),
        onKeyRotate: (d) => world.rotateCamera(d),
    });

    // Интерфейс поверх мира (ДЛ-09): HUD, уведомления, рюкзак, диалоги, настройки.
    const $ = (id) => document.getElementById(id);
    const notifyCenter = createNotifyCenter({
        toastsEl: $('wfToasts'), panelEl: $('wfNotifyPanel'), listEl: $('wfNotes'),
        badgeEl: $('wfBellBadge'), bellEl: $('wfBell'), clearEl: $('wfNotesClear'),
    });
    const notify = (options) => notifyCenter.push(options);
    const dialog = createDialog();
    const settings = createSettingsUi({
        quality,
        notice,
        onOpenBeta: () => beta.open(),
        onRepeatTutorial: () => notify({ text: S.settings.tutorialLater }),
        onAutoPickup: () => {},
    });
    let hud = null;
    let energyFlow = null;
    const inventory = createInventory({
        // Вес ноши замедляет бобра по общей с сервером формуле (ДЛ-13).
        onChange: ({ kg, capacity }) => { if (hud) hud.set({ packKg: kg, packCap: capacity }); world.setSpeedMods({ load: kg, capacity }); },
        onDrop: (key, qty) => { if (packFlow) packFlow.dropItem(key, qty); else notify({ text: S.pack.offline }); },
        onEat: (key) => { if (energyFlow) energyFlow.eat(key); else notify({ text: S.energy.offline }); },
    });
    hud = createHud({
        onAction(id) {
            if (id === 'backpack') { settings.close(); notifyCenter.close(); inventory.toggle(); }
            else if (id === 'build') notify({ text: S.hud.soon.build });
            else if (id === 'helpers') notify({ text: S.hud.soon.helpers });
        },
        onSlot: () => { /* выбор инструмента в руку подключится вместе с рубкой (ДЛ-11) */ },
    });
    hud.onWarn(notify);
    // Быстрые слоты по данным сервера (по умолчанию топор); инструмент в руке выбирает игрок.
    const hotbarKeys = (session.full && Array.isArray(session.full.hotbar) && session.full.hotbar.length ? session.full.hotbar : ['axe']).map(String).filter((k) => S.items[k]);
    hud.setHotbar(hotbarKeys.map((k) => ({ key: k, icon: S.items[k].icon, name: S.items[k].name })));
    $('wfGear').addEventListener('click', () => notifyCenter.close());
    $('wfBell').addEventListener('click', () => settings.close());

    // Рубка (ДЛ-11): шкала над деревом, действие chop_done, разбор ответа сервера.
    let lastEnergy = session.full && session.full.state ? Number(session.full.state.energy) : null;
    let axeLevel = session.full && session.full.upgrades ? Number(session.full.upgrades.axe) || 1 : 1;
    chopFlow = createChopFlow({
        world, wg, bar: createChopBar(), notify, text: S.chop,
        getQueue: () => queue, getSync: () => sync,
        getAxeLevel: () => axeLevel,
        getEnergy: () => (online ? lastEnergy : null),
    });

    // Выкорчёвывание пней (#64): тап по пню с киркой в руке, шкала ударов, действие uproot.
    uprootFlow = createUprootFlow({
        world, bar: createChopBar(), notify, text: S.uproot,
        getQueue: () => queue, getSync: () => sync,
        hasPickaxe: () => hotbarKeys[hud.selected] === 'pickaxe',
        getEnergy: () => (online ? lastEnergy : null),
    });

    // Рюкзак (ДЛ-13): подбор тапом и автоподбор брёвен, бросание груза.
    packFlow = createPackFlow({
        world, notify, text: S.pack,
        names: Object.fromEntries(Object.keys(S.items).map((k) => [k, S.items[k].name.toLowerCase()])),
        getQueue: () => queue, getSync: () => sync,
        getInventory: () => inventory, getCapacity: () => (hud ? hud.values.packCap : 40),
        isAuto: () => settings.autoPickup(), isSleeping: () => (energyFlow ? energyFlow.sleeping : false),
    });

    // Ящик-тайник и скупщик на поляне (ДЛ-14): сдать, взять, продать брёвна и рыбу.
    crateFlow = createCrateFlow({
        world, dialog, inventory, notify, text: S.crate, buyerText: S.buyer,
        crateUi: createCrateUi({ onMove: (m) => crateFlow.move(m) }),
        names: Object.fromEntries(Object.keys(S.items).map((k) => [k, S.items[k].name])),
        getQueue: () => queue, getSync: () => sync,
        isSleeping: () => (energyFlow ? energyFlow.sleeping : false),
    });
    if (start && start.village && start.village.buyer) crateFlow.setPrices(start.village.buyer.prices);

    // Энергия, сон и еда (ДЛ-12): сервер присылает sleeping, бобёр ложится; еда уходит действием eat.
    energyFlow = createEnergyFlow({
        world, notify, text: S.energy,
        getQueue: () => queue, getSync: () => sync,
        onEatLocal: (key) => inventory.remove(key, 1),
    });
    if (session.full && session.full.state) energyFlow.onState(session.full.state);

    // Смена качества: пересоздание рендерера при смене сглаживания, остальное на лету.
    quality.subscribe((next) => {
        const preset = getPreset(next);
        host.apply(preset);
        world.applyQuality(preset);
        world.resize(window.innerWidth, window.innerHeight);
        fps.cancelProbe(); // человек сам выбрал качество, автоподсказка не нужна
    });

    function onResize() {
        host.resize();
        world.resize(window.innerWidth, window.innerHeight);
    }
    window.addEventListener('resize', onResize);
    window.addEventListener('orientationchange', onResize);

    // Автоподсказка: первые секунды мало кадров.
    function suggestLower(avgFps) {
        if (!shouldSuggestLower(avgFps, quality.current)) return false;
        const next = lowerQuality(quality.current);
        notice.show({
            text: `Мало кадров в секунду (${Math.round(avgFps)}). Перейти на качество «${getPreset(next).label}»?`,
            actions: [
                { label: 'Перейти', onClick: () => quality.set(next) },
                { label: 'Нет', ghost: true },
            ],
        });
        return true;
    }

    const debug = isDebugMode() ? createDebugPanel() : null;

    loop = createLoop({
        update: (dt) => { world.update(dt); if (chopFlow) chopFlow.update(); if (packFlow) packFlow.update(); if (uprootFlow) uprootFlow.update(); },
        render: (alpha, now) => {
            world.render(host.renderer, alpha);
            fps.tick(now);
            hud.tick(Date.now());
            const avg = fps.probe(now);
            if (avg !== null) suggestLower(avg);
            if (debug) {
                debug.update(now, { fps: fps.fps, info: host.info(), quality: quality.current, state, trees: world.treeCount, chunks: world.chunks });
            }
        },
        getTargetFps: () => quality.preset.targetFps,
    });

    if (debug) {
        // Хуки для автотестов и отладки (только при ?debug=1).
        window.__wf = {
            get state() { return state; },
            get quality() { return quality.current; },
            setQuality: (q) => quality.set(q),
            info: () => host.info(),
            fps: () => fps.fps,
            frames: () => loop.frames,
            suggestLower,
            loopRunning: () => loop.running && !loop.paused,
            // getExtension на потерянном контексте возвращает null, поэтому расширение запоминаем заранее
            loseContext() {
                loseExt = host.renderer.getContext().getExtension('WEBGL_lose_context');
                if (loseExt) loseExt.loseContext();
                return Boolean(loseExt);
            },
            restoreContext() {
                if (loseExt) loseExt.restoreContext();
                return Boolean(loseExt);
            },
            // мир (ДЛ-06)
            teleport: (x, z) => world.teleport(x, z),
            heroPos: () => world.heroPos,
            setTarget: (x, z) => world.setTarget(x, z),
            moveInput: (x, z) => world.setMoveInput(x, z),
            world: () => ({ loaded: world.chunks.loaded, pending: world.chunks.pending, trees: world.treeCount, stumps: world.chunks.stumpCount }),
            fell: (id) => world.chunks.fell(id),
            clearing: (x, z, r) => world.chunks.addClearing(x, z, r),
            chunkTrees: (cx, cz) => { const c = world.chunks.chunk(cx, cz); return c ? [...c.data.trees.keys()] : null; },
            pumpAll: () => { let n = 0; while (world.chunks.pending > 0 && n < 400) { world.chunks.pump(1000); n += 1; } return world.chunks.loaded; },
            groundY: (x, z) => world.groundY(x, z),
            beaver: () => world.beaver.info(),
            beaverState: (name, opts) => world.setBeaverState(name, opts),
            beaverGear: (gear) => world.beaver.setGear(gear),
            beaverSkin: (skin) => world.beaver.setSkin(skin),
            beaverSwing: () => { world.setBeaverState('chop', { auto: false }); world.beaver.swing(); },
            biomeAt: (x, z) => wg.biomeAt(x, z),
            // управление и камера (ДЛ-08)
            tap: (px, py) => world.handleTap(px, py),
            pickAt: (px, py) => { const o = world.pickAt(px, py); return o ? { id: o.id, kind: o.kind, x: o.x, z: o.z } : null; },
            camera: () => ({ yaw: world.rig.yaw, pitch: world.rig.pitch, dist: world.rig.dist, goalDist: world.rig.goal.dist, goalPitch: world.rig.goal.pitch, goalYaw: world.rig.goal.yaw, y: world.camera.position.y }),
            orbit: (dYaw, dPitch) => world.orbitCamera(dYaw, dPitch),
            zoom: (f) => world.zoomCamera(f),
            snapCamera: () => world.rig.snap(),
            hero: () => ({ mode: world.ctl.mode, obj: world.ctl.obj ? world.ctl.obj.id : null, speed: world.ctl.moveSpeed, partial: world.ctl.partial, path: world.ctl.path.length, yaw: world.ctl.yaw }),
            workHits: () => world.workHits,
            speedLimit: () => world.speedLimit,
            setSpeedMods: (m) => world.setSpeedMods(m),
            track: () => world.track.peek(),
            trackOk: () => checkTrack(world.track.peek(), () => world.speedLimit).ok,
            treeScreen: (id) => {
                const parsed = id.split(':').map(Number);
                const c = world.chunks.chunk(parsed[0], parsed[1]);
                const rec = c && c.data.obs[parsed[2]];
                if (!rec) return null;
                world.camera.updateMatrixWorld();
                const sp = world.projectToScreen(rec.x, rec.y + rec.h * 0.4, rec.z);
                return sp ? { ...sp, x: rec.x, z: rec.z, r: rec.r } : null;
            },
            nearTrees: (range) => { const out = []; world.chunks.forEachInRange(world.ctl.x, world.ctl.z, range, (o) => { if (o.kind === 1) out.push({ id: o.id, x: o.x, z: o.z, r: o.r }); }); return out; },
            blockedAt: (x, z, r) => world.chunks.blockedAt(x, z, r),
            walkable: (x, z) => world.walk.walkable(x, z),
            findPath: (x, z) => { const r = world.nav.findPath(world.ctl.x, world.ctl.z, x, z); return r && { complete: r.complete, points: r.points }; },
            viewSize: () => { const r = stage.getBoundingClientRect(); return { w: r.width, h: r.height }; },
            ui: { hud, inventory, dialog, notify, notifyCenter, settings },
            pondInfo: () => world.pondInfo(),
            chop: () => ({ active: chopFlow.chop.active, hits: chopFlow.chop.hits, frac: chopFlow.chop.frac, need: chopFlow.chop.info && chopFlow.chop.info.need, pending: [...chopFlow.pending.keys()] }),
            drops: () => world.drops.list(),
            pack: () => ({ pending: [...packFlow.pending.keys()], throwing: packFlow.throwing, speed: world.speedLimit }),
            pickup: (id) => packFlow.pickup(id),
            dropItem: (key, qty) => packFlow.dropItem(key, qty),
            energy: () => ({ sleeping: energyFlow.sleeping, value: lastEnergy }),
            eat: (key) => energyFlow.eat(key),
            stalls: () => world.scene.getObjectByName('village-stalls') ? world.scene.getObjectByName('village-stalls').children.length : 0,
            crate: () => ({ pack: crateFlow.pack, box: crateFlow.box, buyerLeft: crateFlow.buyerLeft }),
            crateMove: (from, item, qty) => crateFlow.move({ from, item, qty }),
            sellAll: () => crateFlow.sell(Object.keys(crateFlow.pack).map((item) => ({ item, qty: crateFlow.pack[item] }))),
            beaverStateName: () => world.beaver.state,
            net: { session, get sync() { return sync; }, get queue() { return queue; } },
            throwError: (message) => setTimeout(() => { throw new Error(message || 'тестовая ошибка'); }, 0),
        };
    }

    // Шаг «сервер» (ДЛ-10): сессия уже открыта; недоступность не фатальна, играем без сохранения.
    let sync = null;
    let queue = null;
    if (online) {
        loading.step('server', 'done');
        applyLightState(session.full.state, { hud, inventory, crate: crateFlow });
        queue = createActionQueue(session.full.last_a || 0);
        sync = createSync({
            call: apiCall,
            queue,
            getPos: () => ({ x: Math.round(world.heroPos.x * 100) / 100, z: Math.round(world.heroPos.z * 100) / 100, ry: Math.round(world.ctl.yaw * 100) / 100 }),
            isHidden: () => document.hidden,
            onState: (st) => { lastEnergy = Number(st.energy); applyLightState(st, { hud, inventory, crate: crateFlow }); energyFlow.onState(st); },
            onResults: (results) => { chopFlow.onResults(results); energyFlow.onResults(results); packFlow.onResults(results); uprootFlow.onResults(results); crateFlow.onResults(results); },
            onCorrect: (p) => { world.teleport(p.x, p.z); notify({ text: S.net.corrected }); },
            onStatus: (st) => {
                if (st === 'offline') notify({ text: S.net.offline });
                else if (st === 'online' && prevNetStatus === 'offline') notify({ text: S.net.online });
                else if (st === 'outdated') notice.show({ text: S.net.outdated, ttlMs: 0 });
                else if (st === 'unauthorized') notice.show({ text: S.net.unauthorized, ttlMs: 0 });
                prevNetStatus = st;
            },
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden && sync.running) sync.flush(); });
        sync.start(3000);
        if (session.full.created) setTimeout(() => notify({ text: S.net.welcome }), 1500);
    } else {
        loading.step('server', 'warn', S.net[session.reason] || S.net.error);
        setTimeout(() => notify({ text: S.net[session.reason] || S.net.error }), 1500);
    }

    setState(STATES.PLAYING);
    loop.start();
    // Первый кадр уже нарисован циклом; убираем экран загрузки чуть позже, чтобы не мигало.
    setTimeout(() => {
        loading.hide();
        settings.show();
        hud.show();
        beta.showIfDue();
    }, online ? 150 : 600);
}

boot().catch((error) => {
    errors.report('boot', error);
    errors.showFatal({
        heading: 'Не удалось запустить игру',
        message: 'Игра не смогла стартовать. Перезагрузи страницу.',
        technical: error && error.message ? error.message : String(error),
    });
    loading.hide();
});
