// Распознавание жестов (ДЛ-08). Чистая логика без DOM: принимает события указателей, зовёт обработчики.
//  - один палец или левая кнопка мыши: короткий тап = действие (onTap); перетаскивание одним пальцем ничего не крутит;
//  - два пальца: щипок = зум (onZoom), поворот пальцев = вращение камеры, сдвиг центра = вращение и наклон (onOrbit);
//  - правая или средняя кнопка мыши: вращение и наклон камеры; колесо: зум.
// Жесты «залипают»: канал (зум, поворот, сдвиг) включается, когда выйдет за порог, и до конца жеста работает без мёртвой зоны.
import { INPUT } from '../config.js';

const TAU = Math.PI * 2;
const wrap = (a) => { let r = a % TAU; if (r > Math.PI) r -= TAU; else if (r < -Math.PI) r += TAU; return r; };

export function createGestures({ onTap, onOrbit, onZoom, cfg = INPUT } = {}) {
    const ptrs = new Map(); // id -> { x, y, t0, x0, y0, kind, button, moved }
    let multi = null;       // состояние двухпальцевого жеста
    let tapBlocked = false; // палец сначала стал частью многопальцевого жеста: тапа не будет
    let mouseOrbit = null;  // id указателя мыши, который крутит камеру

    const emitTap = (p, x, y) => onTap && onTap({ x, y, button: p.button, kind: p.kind });

    function startMulti() {
        const [a, b] = [...ptrs.values()];
        multi = {
            dist0: Math.hypot(b.x - a.x, b.y - a.y) || 1,
            dist: Math.hypot(b.x - a.x, b.y - a.y) || 1,
            ang: Math.atan2(b.y - a.y, b.x - a.x),
            midX: (a.x + b.x) / 2,
            midY: (a.y + b.y) / 2,
            accZoom: 0,
            accTwist: 0,
            accPan: 0,
            zoomOn: false,
            twistOn: false,
            panOn: false,
        };
    }

    return {
        down(id, x, y, t, kind = 'touch', button = 0) {
            const first = ptrs.size === 0;
            ptrs.set(id, { x, y, t0: t, x0: x, y0: y, kind, button, moved: false });
            if (kind === 'mouse' && (button === 1 || button === 2)) {
                mouseOrbit = id;
                tapBlocked = true;
                return;
            }
            if (first) tapBlocked = false;
            if (ptrs.size === 2) { tapBlocked = true; startMulti(); }
        },
        move(id, x, y) {
            const p = ptrs.get(id);
            if (!p) return;
            const dx = x - p.x;
            const dy = y - p.y;
            p.x = x;
            p.y = y;
            if (Math.hypot(x - p.x0, y - p.y0) > cfg.tapPx) p.moved = true;
            if (mouseOrbit === id) {
                if (onOrbit) onOrbit(-dx * cfg.orbitYawPerPx, dy * cfg.orbitPitchPerPx);
                return;
            }
            if (multi && ptrs.size >= 2) {
                const [a, b] = [...ptrs.values()];
                const dist = Math.hypot(b.x - a.x, b.y - a.y) || 1;
                const ang = Math.atan2(b.y - a.y, b.x - a.x);
                const midX = (a.x + b.x) / 2;
                const midY = (a.y + b.y) / 2;
                const dAng = wrap(ang - multi.ang);
                const dMidX = midX - multi.midX;
                const dMidY = midY - multi.midY;
                multi.accZoom += Math.abs(dist - multi.dist);
                multi.accTwist += Math.abs(dAng);
                multi.accPan += Math.hypot(dMidX, dMidY);
                if (!multi.zoomOn && Math.abs(dist - multi.dist0) > cfg.pinchThresholdPx) multi.zoomOn = true;
                if (!multi.twistOn && multi.accTwist > cfg.twistThresholdRad) multi.twistOn = true;
                if (!multi.panOn && multi.accPan > cfg.panThresholdPx) multi.panOn = true;
                // щипок: пальцы расходятся = ближе к земле (дистанция камеры уменьшается)
                if (multi.zoomOn && onZoom && dist !== multi.dist) onZoom(multi.dist / dist);
                let dYaw = 0;
                let dPitch = 0;
                if (multi.twistOn) dYaw += dAng;
                if (multi.panOn) {
                    dYaw += -dMidX * cfg.orbitYawPerPx;
                    dPitch += dMidY * cfg.orbitPitchPerPx;
                }
                if ((dYaw !== 0 || dPitch !== 0) && onOrbit) onOrbit(dYaw, dPitch);
                multi.dist = dist;
                multi.ang = ang;
                multi.midX = midX;
                multi.midY = midY;
            }
        },
        up(id, x, y, t) {
            const p = ptrs.get(id);
            if (!p) return;
            ptrs.delete(id);
            if (mouseOrbit === id) { mouseOrbit = null; if (ptrs.size === 0) tapBlocked = false; return; }
            if (ptrs.size < 2) multi = null;
            const isLast = ptrs.size === 0;
            if (!tapBlocked && !p.moved && t - p.t0 <= cfg.tapMs && (p.kind !== 'mouse' || p.button === 0)) emitTap(p, x, y);
            if (isLast) tapBlocked = false;
        },
        cancel(id) {
            ptrs.delete(id);
            if (mouseOrbit === id) mouseOrbit = null;
            if (ptrs.size < 2) multi = null;
            if (ptrs.size === 0) tapBlocked = false;
        },
        wheel(deltaY) {
            if (onZoom && deltaY) onZoom(Math.exp(deltaY * cfg.wheelZoomK));
        },
        get active() { return ptrs.size; },
        get multi() { return Boolean(multi); },
    };
}
