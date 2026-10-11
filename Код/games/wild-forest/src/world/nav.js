// Навигация героя (ДЛ-08): поиск пути A* по сетке 1 м, сглаживание «натянутой нитью», выталкивание из препятствий.
// Чистая логика без three.js: нужны только проходимость земли (walk.walkable) и препятствия (obstacles.blockedAt / forEachNear).
//  - препятствия круглые: стволы, камни, руда; рельеф: глубокая вода и крутые склоны (terrain-nav.js);
//  - если до цели дойти нельзя, возвращается путь к ближайшей достижимой точке (complete = false);
//  - сетка не хранится между вызовами, поиск ограничен радиусом и числом раскрытых клеток.
import { MOVE } from '../config.js';

class MinHeap {
    constructor() { this.k = []; this.p = []; }
    get size() { return this.k.length; }
    push(key, pr) {
        const k = this.k; const p = this.p;
        let i = k.length;
        k.push(key); p.push(pr);
        while (i > 0) {
            const up = (i - 1) >> 1;
            if (p[up] <= pr) break;
            k[i] = k[up]; p[i] = p[up]; i = up;
        }
        k[i] = key; p[i] = pr;
    }
    pop() {
        const k = this.k; const p = this.p;
        const top = k[0];
        const lk = k.pop(); const lp = p.pop();
        const n = k.length;
        if (n > 0) {
            let i = 0;
            for (;;) {
                let c = 2 * i + 1;
                if (c >= n) break;
                if (c + 1 < n && p[c + 1] < p[c]) c += 1;
                if (p[c] >= lp) break;
                k[i] = k[c]; p[i] = p[c]; i = c;
            }
            k[i] = lk; p[i] = lp;
        }
        return top;
    }
}

const NEIGHBORS = [[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]];

export function createNav({ walk, obstacles, cfg = MOVE }) {
    const R = cfg.heroRadius;
    const planR = R + cfg.marginM;
    const cell = cfg.cellM;
    const half = Math.ceil(cfg.searchRadiusM / cell);
    const W = half * 2 + 1;
    const N = W * W;
    const gScore = new Float32Array(N);
    const parent = new Int32Array(N);
    const state = new Uint8Array(N);   // 0 не видели, 1 в очереди, 2 закрыта
    const openMemo = new Uint8Array(N); // 0 неизвестно, 1 свободна, 2 занята

    const isOpen = (x, z, r = planR) => walk.walkable(x, z) && !obstacles.blockedAt(x, z, r);

    /** Свободна ли прямая между точками (проходит ли герой с запасом). Начало отрезка не проверяем в радиусе skipNear. */
    function lineClear(ax, az, bx, bz, r = planR, skipNear = 0.8) {
        const len = Math.hypot(bx - ax, bz - az);
        const steps = Math.max(1, Math.ceil(len / 0.5));
        for (let s = 1; s <= steps; s += 1) {
            const d = (s * len) / steps;
            if (d < skipNear) continue;
            const t = s / steps;
            if (!isOpen(ax + (bx - ax) * t, az + (bz - az) * t, r)) return false;
        }
        return true;
    }

    /** Ближайшая свободная точка к (x, z) в радиусе maxR (кольцами по сетке); null, если нет. */
    function nearestOpen(x, z, maxR = 6) {
        if (isOpen(x, z)) return { x, z };
        let best = null;
        let bestD = Infinity;
        const ci = Math.floor(x / cell);
        const cj = Math.floor(z / cell);
        const rings = Math.ceil(maxR / cell);
        for (let ring = 1; ring <= rings; ring += 1) {
            for (let dj = -ring; dj <= ring; dj += 1) {
                for (let di = -ring; di <= ring; di += 1) {
                    if (Math.max(Math.abs(di), Math.abs(dj)) !== ring) continue;
                    const px = (ci + di + 0.5) * cell;
                    const pz = (cj + dj + 0.5) * cell;
                    const d = Math.hypot(px - x, pz - z);
                    if (d < bestD && d <= maxR && isOpen(px, pz)) { best = { x: px, z: pz }; bestD = d; }
                }
            }
            if (best && bestD <= ring * cell) break;
        }
        return best;
    }

    /** Выталкивает круг радиуса r из препятствий (скольжение вдоль стволов вместо прохода сквозь). */
    function pushOut(x, z, r = R) {
        for (let it = 0; it < 3; it += 1) {
            let moved = false;
            obstacles.forEachNear(x, z, r + 1.4, (o) => {
                let dx = x - o.x;
                let dz = z - o.z;
                const min = r + o.r;
                const d2 = dx * dx + dz * dz;
                if (d2 >= min * min) return;
                let d = Math.sqrt(d2);
                if (d < 1e-6) { dx = 1; dz = 0; d = 1; }
                const push = min - d + 0.001;
                x += (dx / d) * push;
                z += (dz / d) * push;
                moved = true;
            });
            if (!moved) break;
        }
        return { x, z };
    }

    /** Место, где встать, чтобы работать с объектом: ближайшая к (fromX, fromZ) свободная точка кольца вокруг него. */
    function findSpot(obj, dist, fromX, fromZ) {
        for (let extra = 0; extra <= 1.6; extra += 0.8) {
            const d = dist + extra;
            const cand = [];
            for (let k = 0; k < 16; k += 1) {
                const a = (k / 16) * Math.PI * 2;
                const x = obj.x + Math.cos(a) * d;
                const z = obj.z + Math.sin(a) * d;
                cand.push({ x, z, dd: Math.hypot(x - fromX, z - fromZ) });
            }
            cand.sort((p, q) => p.dd - q.dd);
            for (const c of cand) if (isOpen(c.x, c.z, R + 0.05)) return { x: c.x, z: c.z };
        }
        return null;
    }

    /** Поиск пути. Возвращает { points: [{x,z}], complete, end } или null, если сдвинуться некуда. */
    function findPath(sx, sz, tx, tz) {
        const si = Math.floor(sx / cell);
        const sj = Math.floor(sz / cell);
        const idxOf = (i, j) => (j - sj + half) * W + (i - si + half);
        const inRange = (i, j) => Math.abs(i - si) <= half && Math.abs(j - sj) <= half;
        const cx = (i) => (i + 0.5) * cell;
        const openAt = (i, j) => {
            if (!inRange(i, j)) return false;
            const id = idxOf(i, j);
            const m = openMemo[id];
            if (m) return m === 1;
            const x = cx(i);
            const z = cx(j);
            const near = Math.hypot(x - sx, z - sz) < 1.6; // у самого старта (герой мог встать впритык) требования мягче
            const ok = walk.walkable(x, z) && !obstacles.blockedAt(x, z, near ? 0.1 : planR);
            openMemo[id] = ok ? 1 : 2;
            return ok;
        };
        gScore.fill(0); parent.fill(-1); state.fill(0); openMemo.fill(0);

        let gi = Math.floor(tx / cell);
        let gj = Math.floor(tz / cell);
        let goalOk = openAt(gi, gj);
        if (!goalOk) {
            // цель занята (дерево, вода): ближайшая свободная клетка рядом
            let bd = Infinity;
            let bi = gi;
            let bj = gj;
            for (let r = 1; r <= 6 && bd === Infinity; r += 1) {
                for (let dj = -r; dj <= r; dj += 1) {
                    for (let di = -r; di <= r; di += 1) {
                        if (Math.max(Math.abs(di), Math.abs(dj)) !== r) continue;
                        if (!openAt(gi + di, gj + dj)) continue;
                        const d = Math.hypot(cx(gi + di) - tx, cx(gj + dj) - tz);
                        if (d < bd) { bd = d; bi = gi + di; bj = gj + dj; }
                    }
                }
            }
            if (bd < Infinity) { goalOk = true; gi = bi; gj = bj; }
        }
        const gx = goalOk ? cx(gi) : tx; // куда тянемся: центр клетки цели или сама точка, если цель недоступна
        const gz = goalOk ? cx(gj) : tz;
        const H = (i, j) => {
            const dx = Math.abs(cx(i) - gx); const dz = Math.abs(cx(j) - gz);
            return (Math.max(dx, dz) + 0.4142 * Math.min(dx, dz)) * 1.15;
        };

        const startId = idxOf(si, sj);
        const heap = new MinHeap();
        gScore[startId] = 0; state[startId] = 1;
        heap.push(startId, H(si, sj));
        let bestId = startId; let bestH = H(si, sj);
        let endId = -1; let nodes = 0;
        while (heap.size > 0) {
            const id = heap.pop();
            if (state[id] === 2) continue;
            state[id] = 2;
            const i = (id % W) - half + si;
            const j = Math.floor(id / W) - half + sj;
            if (goalOk && i === gi && j === gj) { endId = id; break; }
            nodes += 1;
            if (nodes > cfg.maxNodes) break;
            const h = H(i, j);
            // ближайший к цели узел; при равной близости тот, до которого короче идти
            if (h < bestH - 1e-6 || (h <= bestH + 1e-6 && gScore[id] < gScore[bestId])) { bestH = Math.min(bestH, h); bestId = id; }
            for (const [di, dj] of NEIGHBORS) {
                const ni2 = i + di; const nj2 = j + dj;
                if (!openAt(ni2, nj2)) continue;
                if (di !== 0 && dj !== 0 && (!openAt(i + di, j) || !openAt(i, j + dj))) continue; // углы не срезаем
                const nid = idxOf(ni2, nj2);
                if (state[nid] === 2) continue;
                const ng = gScore[id] + (di !== 0 && dj !== 0 ? 1.4142 : 1) * cell;
                if (state[nid] === 0 || ng < gScore[nid]) {
                    gScore[nid] = ng; parent[nid] = id; state[nid] = 1;
                    heap.push(nid, ng + H(ni2, nj2));
                }
            }
        }
        const complete = endId >= 0;
        const finalId = complete ? endId : bestId;
        if (!complete && finalId === startId) return null;
        const cells = [];
        for (let id = finalId; id !== -1 && id !== startId; id = parent[id]) {
            cells.push({ x: cx((id % W) - half + si), z: cx(Math.floor(id / W) - half + sj) });
        }
        cells.reverse();
        if (!complete) {
            const last = cells[cells.length - 1];
            if (!last || Math.hypot(last.x - sx, last.z - sz) < 1) return null;
        } else if (cells.length > 0 && isOpen(tx, tz)) {
            cells[cells.length - 1] = { x: tx, z: tz }; // точный конец
        } else if (cells.length === 0) {
            cells.push({ x: isOpen(tx, tz) ? tx : cx(gi), z: isOpen(tx, tz) ? tz : cx(gj) });
        }
        // сглаживание: от якоря тянемся к самой дальней точке, до которой путь прямой
        const pts = [{ x: sx, z: sz }, ...cells];
        const out = [];
        let anchor = 0;
        const last = pts.length - 1;
        while (anchor < last) {
            let j = last;
            while (j > anchor + 1 && !lineClear(pts[anchor].x, pts[anchor].z, pts[j].x, pts[j].z)) j -= 1;
            out.push(pts[j]);
            anchor = j;
        }
        return { points: out, complete, end: out[out.length - 1] || { x: sx, z: sz } };
    }

    return { isOpen, lineClear, nearestOpen, pushOut, findSpot, findPath, walkable: walk.walkable, heroRadius: R };
}
