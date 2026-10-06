import { LOW_FPS } from '../config.js';

// Счётчик FPS: окно 500 мс для отладки и накопитель для автоподсказки качества.
export function createFpsMeter() {
    let windowStart = 0;
    let windowFrames = 0;
    let fps = 0;

    let probeStart = 0;
    let probeFrames = 0;
    let probeFrom = 0;
    let probeDone = false;

    return {
        get fps() { return fps; },
        tick(now) {
            if (!windowStart) windowStart = now;
            windowFrames += 1;
            if (now - windowStart >= 500) {
                fps = (windowFrames * 1000) / (now - windowStart);
                windowStart = now;
                windowFrames = 0;
            }
            if (!probeStart) probeStart = now;
        },
        // Проба первых секунд: возвращает средний FPS один раз, когда окно закончилось.
        probe(now) {
            if (probeDone || !probeStart) return null;
            const elapsed = now - probeStart;
            if (elapsed < LOW_FPS.warmupMs) return null;
            if (!probeFrom) {
                probeFrom = now;
                probeFrames = 0;
                return null;
            }
            probeFrames += 1;
            if (elapsed >= LOW_FPS.windowMs) {
                probeDone = true;
                const span = now - probeFrom;
                return span > 0 ? (probeFrames * 1000) / span : null;
            }
            return null;
        },
        resetProbe() {
            probeStart = 0;
            probeFrom = 0;
            probeFrames = 0;
            probeDone = false;
        },
        cancelProbe() { probeDone = true; },
    };
}
