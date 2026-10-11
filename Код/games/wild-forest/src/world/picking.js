// Выбор объекта под пальцем (ДЛ-08). Чистая логика: объекты проецируются на экран как отрезки «земля → верхушка»,
// выбирается тот, что ближе всего к точке касания в пределах «толстого пальца»; из почти равных берётся ближний к камере.
// Землёй занимается трассировка луча по функции высоты (scene.pickGround), поэтому меши не нужны.
import { INPUT } from '../config.js';

function segDist(px, py, ax, ay, bx, by) {
    const vx = bx - ax; const vy = by - ay;
    const l2 = vx * vx + vy * vy;
    const t = l2 > 0 ? Math.max(0, Math.min(1, ((px - ax) * vx + (py - ay) * vy) / l2)) : 0;
    return Math.hypot(px - (ax + vx * t), py - (ay + vy * t));
}

/**
 * @param {Iterable<{id,kind,x,y,z,h,pickR}>} candidates объекты (x, z, y основания, h высота, pickR радиус попадания в метрах)
 * @param {(x,y,z)=>{sx:number,sy:number}|null} project мир -> экран в CSS-пикселях (null, если за камерой)
 * @param {{x,y,z}} cam положение камеры; viewH высота экрана, fov вертикальный угол в радианах
 * @returns объект-кандидат или null
 */
export function pickObject({ candidates, project, cam, px, py, viewH, fov, cfg = INPUT, maxDist = 120, slackPx = 6 }) {
    const pxPerM = (d) => (viewH / 2) / (Math.tan(fov / 2) * Math.max(0.5, d));
    const hits = [];
    let bestDist = Infinity;
    for (const o of candidates) {
        const d = Math.hypot(o.x - cam.x, (o.y + o.h * 0.5) - cam.y, o.z - cam.z);
        if (d > maxDist) continue;
        const base = project(o.x, o.y + 0.2, o.z);
        const top = project(o.x, o.y + Math.max(0.5, o.h * 0.8), o.z);
        if (!base || !top) continue;
        const radius = Math.max(cfg.pickMinPx, Math.min(cfg.pickMaxPx, (o.pickR || cfg.pickTreeWorldR) * pxPerM(d)));
        const dist = segDist(px, py, base.sx, base.sy, top.sx, top.sy);
        if (dist > radius) continue;
        hits.push({ o, dist, d });
        if (dist < bestDist) bestDist = dist;
    }
    let pick = null;
    for (const h of hits) {
        if (h.dist > bestDist + slackPx) continue;
        if (!pick || h.d < pick.d) pick = h;
    }
    return pick ? pick.o : null;
}
