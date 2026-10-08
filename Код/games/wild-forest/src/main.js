import { STATES } from './config.js';
import { renderBuildId, startAutoUpdate } from './update.js';
import { createQualityController, lowerQuality, shouldSuggestLower, getPreset } from './core/quality.js';
import { createRendererHost, detectWebGL } from './core/renderer.js';
import { createLoop } from './core/loop.js';
import { createFpsMeter } from './core/fps.js';
import { createWorldScene } from './world/scene.js';
import { loadWorldGen } from './world/gen.js';
import { installDemoInput } from './core/demo-input.js';
import { checkServer } from './net/index.js';
import { createLoadingScreen } from './ui/loading.js';
import { createNotice } from './ui/notice.js';
import { createErrorUi } from './ui/errors.js';
import { createSettingsUi } from './ui/settings.js';
import { createBetaNotice } from './ui/beta.js';
import { createDebugPanel, isDebugMode } from './ui/debug.js';

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

    try {
        host.apply(quality.preset);
        world = createWorldScene({ wg });
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

    installDemoInput({ canvasHost: stage, world, isActive: () => state === STATES.PLAYING });

    const settings = createSettingsUi({ quality, onOpenBeta: () => beta.open() });

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
        update: (dt) => world.update(dt),
        render: (alpha, now) => {
            world.render(host.renderer, alpha);
            fps.tick(now);
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
            biomeAt: (x, z) => wg.biomeAt(x, z),
            throwError: (message) => setTimeout(() => { throw new Error(message || 'тестовая ошибка'); }, 0),
        };
    }

    // Шаг «сервер»: недоступность не фатальна (каркас работает и без сервера).
    const server = await checkServer();
    if (server.ok) {
        loading.step('server', server.schemaOk ? 'done' : 'warn', server.schemaOk ? '' : 'Схема сервера ещё обновляется');
    } else {
        loading.step('server', 'warn', 'Сервер недоступен, играем без него');
    }

    setState(STATES.PLAYING);
    loop.start();
    // Первый кадр уже нарисован циклом; убираем экран загрузки чуть позже, чтобы не мигало.
    setTimeout(() => {
        loading.hide();
        settings.show();
        beta.showIfDue();
    }, server.ok ? 150 : 600);
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
