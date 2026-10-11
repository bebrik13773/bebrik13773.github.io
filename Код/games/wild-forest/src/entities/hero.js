// Контроллер героя (ДЛ-08). Чистая логика без three.js: приказы (идти в точку, подойти к объекту, ходить клавишами),
// следование по пути, разгон, поворот, скольжение вдоль стволов, защита от застревания, события для игры.
// Режимы: idle, move (идёт в точку), approach (идёт к объекту), turn (поворачивается к объекту), work (работает с объектом), key (клавиши).
import { MOVE } from '../config.js';

const angleDiff = (a, b) => Math.atan2(Math.sin(a - b), Math.cos(a - b));
const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);

export function createHero({ nav, x = 0, z = 0, vMax = 3.2, cfg = MOVE }) {
    const s = {
        x, z, px: x, pz: z, yaw: 0, pyaw: 0,
        speed: 0,        // скорость по приказу (разгон), м/с
        moveSpeed: 0,    // фактическая сглаженная скорость для анимации
        vMax,
        mode: 'idle', path: [], idx: 0, goal: null, partial: false, replans: 0, replanFrom: null,
        obj: null, input: { x: 0, z: 0, yaw: 0 }, stuckT: 0, strikes: 0, winT: 0, winWant: 0, winProg: 0,
    };
    const handlers = {};
    const emit = (name, arg) => { if (handlers[name]) handlers[name](arg); };
    const spotDist = (obj) => Math.max(cfg.interactReach, (obj.r || 0) + cfg.heroRadius + 0.5);

    function clearOrder() { s.path = []; s.idx = 0; s.goal = null; s.partial = false; s.replans = 0; s.replanFrom = null; s.stuckT = 0; s.strikes = 0; }

    function stop(reason) {
        const was = s.mode;
        s.mode = 'idle'; s.obj = null; clearOrder();
        if (reason && was !== 'idle') emit(reason, { x: s.x, z: s.z });
    }

    function startPath(tx, tz, mode) {
        const r = nav.findPath(s.x, s.z, tx, tz);
        if (!r) return null;
        s.path = r.points; s.idx = 0; s.goal = { x: tx, z: tz }; s.partial = !r.complete;
        s.mode = mode; s.stuckT = 0;
        return r;
    }

    function replan() {
        if (!s.goal) return false;
        const r = nav.findPath(s.x, s.z, s.goal.x, s.goal.z);
        if (!r) return false;
        s.path = r.points; s.idx = 0; s.partial = !r.complete; s.stuckT = 0;
        return true;
    }

    function finishPath() {
        if (s.mode === 'approach') { s.mode = 'turn'; s.path = []; s.idx = 0; return; }
        const left = s.goal ? Math.hypot(s.goal.x - s.x, s.goal.z - s.z) : 0;
        const movedSince = s.replanFrom ? Math.hypot(s.x - s.replanFrom.x, s.z - s.replanFrom.z) : Infinity;
        if (s.partial && s.goal && left > 1.5 && s.replans < cfg.maxReplans && movedSince > 1.5) {
            s.replanFrom = { x: s.x, z: s.z };
            s.replans += 1;
            if (replan()) return;
        }
        const complete = !s.partial || left <= 1.5;
        s.mode = 'idle'; clearOrder();
        emit('arrive', { x: s.x, z: s.z, complete });
    }

    /** Приказ: идти в точку. Возвращает { ok, partial } (partial = дойдём только до ближайшей достижимой точки). */
    function moveTo(tx, tz) {
        s.obj = null; clearOrder();
        const r = startPath(tx, tz, 'move');
        if (!r) { s.mode = 'idle'; return { ok: false, partial: false }; }
        return { ok: true, partial: !r.complete };
    }

    /** Приказ: подойти к объекту { id, kind, x, z, r } и встать лицом к нему. */
    function interact(obj) {
        clearOrder();
        s.obj = obj;
        const D = spotDist(obj);
        if (Math.hypot(obj.x - s.x, obj.z - s.z) <= D + 0.3) { s.mode = 'turn'; return { ok: true, partial: false }; }
        const spot = nav.findSpot(obj, D, s.x, s.z);
        if (!spot || !startPath(spot.x, spot.z, 'approach')) { s.obj = null; s.mode = 'idle'; return { ok: false, partial: false }; }
        return { ok: true, partial: s.partial };
    }

    function step(dt) {
        s.px = s.x; s.pz = s.z; s.pyaw = s.yaw;
        let dx = 0; let dz = 0; let want = 0; let following = false;

        if (s.input.x !== 0 || s.input.z !== 0) {
            if (s.mode !== 'key') { s.obj = null; clearOrder(); s.mode = 'key'; }
            const yw = s.input.yaw;
            dx = -Math.sin(yw) * s.input.z + Math.cos(yw) * s.input.x;
            dz = -Math.cos(yw) * s.input.z - Math.sin(yw) * s.input.x;
            const len = Math.hypot(dx, dz) || 1;
            dx /= len; dz /= len; want = s.vMax;
        } else if (s.mode === 'key') {
            s.mode = 'idle';
        } else if (s.mode === 'move' || s.mode === 'approach') {
            while (s.idx < s.path.length) {
                const w = s.path[s.idx];
                const eps = s.idx === s.path.length - 1 ? cfg.arriveEps : 0.45;
                if (Math.hypot(w.x - s.x, w.z - s.z) <= eps) s.idx += 1; else break;
            }
            if (s.idx >= s.path.length) finishPath();
            if ((s.mode === 'move' || s.mode === 'approach') && s.idx < s.path.length) {
                const w = s.path[s.idx];
                dx = w.x - s.x; dz = w.z - s.z;
                const len = Math.hypot(dx, dz) || 1;
                dx /= len; dz /= len; want = s.vMax; following = true;
            }
        }

        if (s.mode === 'turn' || s.mode === 'work') {
            const to = Math.atan2(s.obj.x - s.x, s.obj.z - s.z);
            const diff = angleDiff(to, s.yaw);
            const maxTurn = cfg.turnRate * dt;
            s.yaw += clamp(diff, -maxTurn, maxTurn);
            if (s.mode === 'turn' && Math.abs(angleDiff(to, s.yaw)) < 0.2) { s.mode = 'work'; emit('ready', s.obj); }
        }

        // разгон и торможение; на крутом повороте притормаживаем
        let k = 1;
        if (want > 0) {
            const err = Math.abs(angleDiff(Math.atan2(dx, dz), s.yaw));
            k = clamp(1 - err / (Math.PI * 0.7), 0.2, 1);
        }
        const target = want * k;
        if (target > s.speed) s.speed = Math.min(target, s.speed + cfg.accel * dt);
        else s.speed = Math.max(target, s.speed - cfg.accel * 1.5 * dt);

        let moved = 0;
        let progress = 0; // сдвиг именно в нужную сторону (туда-сюда на месте не считается)
        if (want > 0 && s.speed > 0) {
            const turnTo = Math.atan2(dx, dz);
            const maxTurn = cfg.turnRate * dt;
            s.yaw += clamp(angleDiff(turnTo, s.yaw), -maxTurn, maxTurn);
            let stepLen = s.speed * dt;
            if (following) {
                const last = s.path[s.path.length - 1];
                if (s.idx === s.path.length - 1) stepLen = Math.min(stepLen, Math.hypot(last.x - s.x, last.z - s.z));
            }
            let nx = s.x + dx * stepLen;
            let nz = s.z + dz * stepLen;
            ({ x: nx, z: nz } = nav.pushOut(nx, nz));
            if (!nav.walkable(nx, nz)) {
                const ax = nav.pushOut(s.x + dx * stepLen, s.z);
                const az = nav.pushOut(s.x, s.z + dz * stepLen);
                if (nav.walkable(ax.x, ax.z)) { nx = ax.x; nz = ax.z; }
                else if (nav.walkable(az.x, az.z)) { nx = az.x; nz = az.z; }
                else { nx = s.x; nz = s.z; }
            }
            moved = Math.hypot(nx - s.x, nz - s.z);
            progress = (nx - s.x) * dx + (nz - s.z) * dz;
            s.x = nx; s.z = nz;
        }
        s.moveSpeed += ((dt > 0 ? moved / dt : 0) - s.moveSpeed) * Math.min(1, dt * 8);

        // застревание: за окно stuckSeconds прошли меньше четверти того, что должны (дрожание туда-сюда не считается)
        if (following && dt > 0) {
            s.winT += dt; s.winWant += want * dt; s.winProg += progress;
            if (s.winT >= cfg.stuckSeconds) {
                const stuck = s.winProg < s.winWant * 0.25;
                s.winT = 0; s.winWant = 0; s.winProg = 0;
                if (stuck) {
                    s.strikes += 1;
                    if (s.strikes === 1 && replan()) { /* перестроили, пробуем ещё раз */ } else { stop('blocked'); }
                }
            }
        } else {
            s.winT = 0; s.winWant = 0; s.winProg = 0;
        }
    }

    return {
        moveTo,
        interact,
        stop: () => stop(null),
        /** Выйти из режима работы с объектом (тап по земле, движение). */
        cancelWork() { if (s.mode === 'work' || s.mode === 'turn') stop(null); },
        setInput(ix, iz, yaw) { s.input.x = ix; s.input.z = iz; s.input.yaw = yaw; },
        setSpeedLimit(v) { s.vMax = v; },
        teleport(nx, nz) { s.x = nx; s.z = nz; s.px = nx; s.pz = nz; s.speed = 0; s.moveSpeed = 0; s.mode = 'idle'; s.obj = null; clearOrder(); },
        on(name, fn) { handlers[name] = fn; },
        update: step,
        get x() { return s.x; },
        get z() { return s.z; },
        get px() { return s.px; },
        get pz() { return s.pz; },
        get yaw() { return s.yaw; },
        get pyaw() { return s.pyaw; },
        get moveSpeed() { return s.moveSpeed; },
        get mode() { return s.mode; },
        get obj() { return s.obj; },
        get goal() { return s.goal; },
        get path() { return s.path; },
        get pathIdx() { return s.idx; },
        get partial() { return s.partial; },
        get vMax() { return s.vMax; },
    };
}
