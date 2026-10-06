import { LOOP } from '../config.js';

// Главный цикл: логика шагает с фиксированной частотой, кадры рисуются с интерполяцией alpha.
// Ограничитель кадров держит targetFps пресета. На скрытой вкладке цикл полностью стоит.
export function createLoop({ update, render, getTargetFps, onPause, onResume }) {
    const stepMs = 1000 / LOOP.logicHz;
    let running = false;
    let paused = false;      // вкладка скрыта или внешняя пауза (потеря контекста)
    let rafId = 0;
    let last = 0;
    let acc = 0;
    let lastRender = 0;
    let frames = 0;

    function frame(now) {
        rafId = 0;
        if (!running || paused) return;
        rafId = requestAnimationFrame(frame);

        const delta = Math.min(now - last, LOOP.maxFrameMs);
        last = now;
        acc += delta;

        let steps = 0;
        while (acc >= stepMs && steps < LOOP.maxSteps) {
            update(stepMs / 1000);
            acc -= stepMs;
            steps += 1;
        }
        if (steps >= LOOP.maxSteps) acc = 0; // не догоняем бесконечно

        const targetFps = Math.max(1, getTargetFps ? getTargetFps() : 60);
        if (now - lastRender >= 1000 / targetFps - 2) {
            lastRender = now;
            frames += 1;
            render(acc / stepMs, now);
        }
    }

    function kick() {
        if (running && !paused && !rafId) {
            last = performance.now();
            rafId = requestAnimationFrame(frame);
        }
    }

    function setPaused(value, reason) {
        if (paused === value) return;
        paused = value;
        if (paused) {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = 0;
            if (onPause) onPause(reason);
        } else {
            acc = 0;
            kick();
            if (onResume) onResume(reason);
        }
    }

    function onVisibility() {
        setPaused(document.hidden, 'visibility');
    }

    return {
        start() {
            if (running) return;
            running = true;
            document.addEventListener('visibilitychange', onVisibility);
            paused = document.hidden;
            if (!paused) kick();
        },
        stop() {
            running = false;
            document.removeEventListener('visibilitychange', onVisibility);
            if (rafId) cancelAnimationFrame(rafId);
            rafId = 0;
        },
        pause(reason) { setPaused(true, reason || 'manual'); },
        resume(reason) { if (!document.hidden) setPaused(false, reason || 'manual'); },
        get running() { return running; },
        get paused() { return paused; },
        get frames() { return frames; },
        get stepMs() { return stepMs; },
    };
}
