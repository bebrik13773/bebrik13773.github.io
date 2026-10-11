// Орбитальная камера от третьего лица (ДЛ-08). Чистая логика: углы и дальность с плавным догоном цели.
// Вращение, наклон и зум меняют ЦЕЛЬ, а текущие значения догоняют её «пружиной» (одинаково приятно при любой частоте кадров).
import { CAMERA } from '../config.js';

const DEG = Math.PI / 180;
const TAU = Math.PI * 2;
const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);

export function createCameraRig({ cfg = CAMERA, yaw = 0.8 } = {}) {
    const minPitch = cfg.minPitchDeg * DEG;
    const maxPitch = cfg.maxPitchDeg * DEG;
    const goal = { yaw, pitch: cfg.defaultPitchDeg * DEG, dist: cfg.defaultDist };
    const cur = { yaw, pitch: goal.pitch, dist: goal.dist };
    let userZoomed = false; // пока игрок не зумил сам, дальность подстраивается под экран (портрет/ландшафт)

    return {
        goal,
        cur,
        get yaw() { return cur.yaw; },
        get pitch() { return cur.pitch; },
        get dist() { return cur.dist; },
        orbit(dYaw, dPitch) {
            goal.yaw += dYaw;
            goal.pitch = clamp(goal.pitch + dPitch, minPitch, maxPitch);
        },
        zoom(factor) {
            userZoomed = true;
            goal.dist = clamp(goal.dist * factor, cfg.minDist, cfg.maxDist);
        },
        /** Подходящая дальность по умолчанию для экрана; не трогает выбор игрока, если он уже зумил. */
        setDefaultDist(d) {
            if (!userZoomed) goal.dist = clamp(d, cfg.minDist, cfg.maxDist);
        },
        reset() {
            userZoomed = false;
            goal.pitch = cfg.defaultPitchDeg * DEG;
            goal.dist = cfg.defaultDist;
        },
        step(dt) {
            const k = 1 - Math.exp(-cfg.smoothPerSec * Math.max(0, dt));
            cur.yaw += (goal.yaw - cur.yaw) * k;
            cur.pitch += (goal.pitch - cur.pitch) * k;
            cur.dist += (goal.dist - cur.dist) * k;
            if (Math.abs(goal.yaw) > TAU * 8) { goal.yaw %= TAU; cur.yaw %= TAU; } // углы не растут без конца
        },
        snap() { cur.yaw = goal.yaw; cur.pitch = goal.pitch; cur.dist = goal.dist; },
    };
}

/**
 * Камера не должна уходить под землю или за холм: ищем наибольшую дальность ≤ wanted, при которой по пути
 * камера выше рельефа не менее чем на minClear. dir это единичный вектор от точки взгляда к камере.
 */
export function resolveCameraDistance(focus, dir, wanted, groundY, minClear, minDist = 2.5, steps = 8) {
    for (let s = 1; s <= steps; s += 1) {
        const d = (wanted * s) / steps;
        const x = focus.x + dir.x * d;
        const y = focus.y + dir.y * d;
        const z = focus.z + dir.z * d;
        if (y < groundY(x, z) + minClear) return Math.max(minDist, (wanted * (s - 1)) / steps);
    }
    return wanted;
}
