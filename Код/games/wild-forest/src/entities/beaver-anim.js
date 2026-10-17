// Анимация 3D-бобра (ДЛ-07, переработана в #61). Чистая логика без Three.js: из состояния и времени получается «поза» —
// набор чисел (повороты костей, смещение таза, сжатие, видимость предметов). Дальше beaver.js
// кладёт эти числа в кости. Так анимацию можно проверять в Node без графики.
//
// Оси модели: +Z вперёд, +Y вверх, +X налево (бобёр смотрит в +Z). Положительный поворот вокруг X
// наклоняет верх вперёд, а у висящей руки или ноги отводит конец НАЗАД; вперёд и вверх руку поднимает минус.
// Локоть сгибается минусом (предплечье уходит вперёд-вверх). У стопы плюс опускает носок, минус поднимает.
// Топор лежит в кисти рукоятью вперёд, лезвием вниз; сумма углов плеча, локтя и кисти даёт наклон рукояти:
// 0 горизонтально, минус поднимает головку вверх (−1.57 рукоять вертикально), плюс опускает.

export const BONES = Object.freeze(['root', 'hips', 'torso', 'head', 'armL', 'armR', 'foreL', 'foreR', 'legL', 'legR', 'footL', 'footR', 'tail1', 'tail2', 'axe', 'logs', 'food']);
export const B = Object.freeze(Object.fromEntries(BONES.map((name, i) => [name, i])));

// Каналы позы: по три угла (x, y, z) на каждую кость, дальше служебные.
const BASE = BONES.length * 3;
export const CH = Object.freeze({
    ROOT_Y: BASE,       // подпрыгивание всего бобра
    HIPS_X: BASE + 1,   // смещение таза (шаг в сторону, перекос)
    HIPS_Y: BASE + 2,   // таз вверх/вниз (дыхание, шаг, сон на земле)
    HIPS_Z: BASE + 3,   // таз вперёд/назад (выпад при ударе)
    SQUASH: BASE + 4,   // сжатие при ударе, 0..1
    VIS_AXE: BASE + 5,  // 0..1 видимость топора в лапе
    VIS_LOGS: BASE + 6, // 0..1 видимость охапки брёвен
    VIS_FOOD: BASE + 7, // 0..1 видимость рыбы у рта
    COUNT: BASE + 8,
});

/** Индекс канала угла: bone по имени, axis 0=x, 1=y, 2=z. */
export const rot = (bone, axis) => B[bone] * 3 + axis;

export const STATE_NAMES = Object.freeze(['idle', 'walk', 'run', 'chop', 'carry', 'sleep', 'scared', 'eat']);

export const BLEND_S = 0.15;       // плавный переход между состояниями, с
export const IMPACT_PHASE = 0.66;  // момент удара в цикле рубки (0..1)
export const MAX_CHOP_RATE = 3.6;  // быстрее взмахи не рисуем (сервер считает удары отдельно)

const TAU = Math.PI * 2;
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const lerp = (a, b, t) => a + (b - a) * t;
const smooth = (t) => { const k = clamp(t, 0, 1); return k * k * (3 - 2 * k); };

/**
 * Кривая одного взмаха топором. p = 0..1: подъём, замах, удар, возврат.
 * Возвращает углы: arm (плечо), fore (локоть), wrist (кисть с топором), lean (наклон корпуса), twist (скрутка корпуса), step (выпад таза).
 */
export function chopCurve(p) {
    let arm; let fore; let wrist; let lean; let twist; let step;
    if (p < 0.40) {            // подъём: топор уходит за плечо, корпус прогибается назад
        const k = smooth(p / 0.40);
        arm = lerp(-0.7, -2.9, k); fore = lerp(-1.1, -0.65, k); wrist = lerp(0.9, 0.9, k);
        lean = lerp(0.06, -0.20, k); twist = lerp(0, -0.40, k); step = lerp(0, -0.03, k);
    } else if (p < 0.50) {     // замах: короткая пауза наверху, топор чуть уходит назад
        const k = smooth((p - 0.40) / 0.10);
        arm = lerp(-2.9, -3.05, k); fore = -0.65; wrist = 0.9;
        lean = lerp(-0.20, -0.23, k); twist = lerp(-0.40, -0.44, k); step = -0.03;
    } else if (p < IMPACT_PHASE) { // удар вниз: ускоряется, корпус бросается вперёд
        const k = (p - 0.5) / (IMPACT_PHASE - 0.5);
        const e = k * k;
        arm = lerp(-3.05, -1.25, e); fore = lerp(-0.65, -0.5, e); wrist = lerp(0.9, 1.05, e);
        lean = lerp(-0.23, 0.42, e); twist = lerp(-0.44, 0.30, e); step = lerp(-0.03, 0.07, e);
    } else {                   // возврат
        const k = (p - IMPACT_PHASE) / (1 - IMPACT_PHASE);
        const e = 1 - (1 - k) * (1 - k);
        arm = lerp(-1.25, -0.7, e); fore = lerp(-0.5, -1.1, e); wrist = lerp(1.05, 0.9, e);
        lean = lerp(0.42, 0.06, e); twist = lerp(0.30, 0, e); step = lerp(0.07, 0, e);
    }
    return { arm, fore, wrist, lean, twist, step };
}

// Хват топора правой лапой вне рубки: рукоять уходит вперёд-вверх, головка над плечом.
function holdAxe(out, set, sway = 0, bob = 0) {
    set('armR', -0.35 + sway, 0, 0.14);
    set('foreR', -1.15 + bob * 0.5, 0, 0);
    out[rot('axe', 0)] = 0.30 + bob; // сумма −1.2: рукоять почти вертикально
}

/**
 * Целевая поза состояния (без сглаживания). out заполняется с нуля.
 * c: { t (время в состоянии, с), gait (фаза шага в циклах), move (0..1.4, как быстро идёт), load (0..1, нагрузка), chopP (фаза взмаха 0..1), impact (0..1) }.
 */
export function buildPose(out, state, c) {
    out.fill(0);
    out[CH.VIS_AXE] = 1;
    const set = (bone, x = 0, y = 0, z = 0) => { out[rot(bone, 0)] = x; out[rot(bone, 1)] = y; out[rot(bone, 2)] = z; };
    const t = c.t;

    switch (state) {
    case 'idle': {
        const breath = Math.sin(TAU * t / 2.8);
        const shift = Math.sin(TAU * t / 7.0);              // медленный перенос веса с ноги на ногу
        out[CH.HIPS_Y] = 0.008 * breath - 0.004 * Math.abs(shift);
        out[CH.HIPS_X] = 0.012 * shift;
        set('hips', 0, 0, 0.025 * shift);
        set('torso', 0.012 * breath, 0.03 * Math.sin(TAU * t / 5.1), -0.02 * shift);
        const look = Math.sin(TAU * t / 6.5);
        set('head', 0.05 * Math.sin(TAU * t / 4.1) + 0.01 * breath, 0.38 * look * smooth(Math.abs(look) * 2.2), 0.03 * shift);
        set('armL', 0.04 * Math.sin(TAU * t / 2.8 + 0.5), 0, 0.07);
        set('foreL', -0.18 - 0.04 * breath, 0, 0);
        holdAxe(out, set, 0.02 * Math.sin(TAU * t / 2.8 + 1.2), 0.03 * breath);
        set('legL', 0.03 * shift, 0, 0); set('legR', -0.03 * shift, 0, 0);
        set('footL', -0.03 * shift, 0, 0); set('footR', 0.03 * shift, 0, 0);
        set('tail1', 0.03 * breath, 0.14 * Math.sin(TAU * t / 3.3), 0);
        set('tail2', 0, 0.20 * Math.sin(TAU * t / 3.3 - 0.8), 0);
        break;
    }
    case 'walk': case 'run': case 'carry': {
        locomotion(out, set, c, state);
        break;
    }
    case 'chop': {
        const k = chopCurve(c.chopP);
        const rec = c.impact;                                   // отдача после удара
        set('armR', k.arm + 0.10 * rec, 0, 0.12);
        set('foreR', k.fore, 0, 0);
        out[rot('axe', 0)] = k.wrist - 0.12 * rec;
        // левая лапа ведёт рукоять: идёт за правой с запасом хода
        set('armL', 0.5 * k.arm - 0.15, 0, -0.30);
        set('foreL', -0.75 + 0.25 * (k.fore + 0.8), 0, 0);
        set('torso', k.lean + 0.05 * rec, k.twist, 0);
        set('hips', 0, -0.35 * k.twist, 0);
        out[CH.HIPS_Z] = k.step;
        out[CH.HIPS_Y] = -0.055 * smooth((k.lean - 0.1) / 0.32);  // приседает на ударе
        const stoop = smooth((k.lean - 0.1) / 0.32);
        set('head', 0.06 + 0.12 * stoop - 0.5 * k.lean * 0.4, -0.5 * k.twist, 0);
        set('legL', -0.34 - 0.10 * stoop, 0, 0.04);             // широкая устойчивая стойка
        set('legR', 0.26 + 0.10 * stoop, 0, -0.04);
        set('footL', 0.34 + 0.10 * stoop, 0, 0);
        set('footR', -0.26 - 0.10 * stoop, 0, 0);
        set('tail1', 0.25 - 0.45 * k.lean, 0.2 * k.twist, 0);   // хвост уравновешивает
        set('tail2', 0.1 - 0.3 * k.lean, 0.3 * k.twist, 0);
        out[CH.SQUASH] = c.impact;
        break;
    }
    case 'sleep': {
        const breath = Math.sin(TAU * t / 3.2);
        out[CH.HIPS_Y] = -0.07 + 0.01 * breath;       // тело лежит на боку, чуть вдавливается в землю
        out[CH.HIPS_X] = 0.02;
        set('hips', 0, 0, 1.42);
        set('torso', 0.55, 0, 0);
        set('head', 0.78 + 0.02 * breath, 0, -0.15);
        set('armL', -1.0, 0, -0.5); set('foreL', -0.9, 0, 0);
        set('armR', -1.0, 0, 0.5); set('foreR', -0.9, 0, 0);
        set('legL', -1.4, 0, 0); set('legR', -1.25, 0, 0);
        set('footL', 0.5, 0, 0); set('footR', 0.4, 0, 0);
        set('tail1', 0, 1.15, 0);
        set('tail2', 0, 1.0, 0);
        out[CH.SQUASH] = 0.04 * (0.5 + 0.5 * breath);
        out[CH.VIS_AXE] = 0;
        break;
    }
    case 'scared': {
        const hop = Math.max(0, Math.sin(TAU * 2.1 * t));
        out[CH.ROOT_Y] = 0.16 * hop;
        set('hips', 0, 0, 0.05 * Math.sin(TAU * 9 * t));
        set('armL', -2.4, 0, 0.45); set('foreL', -0.4, 0, 0);
        set('armR', -2.4, 0, -0.45); set('foreR', -0.4, 0, 0);
        out[rot('axe', 0)] = 0.2;
        set('head', -0.18 + 0.04 * Math.sin(TAU * 11 * t), 0, 0);
        set('torso', -0.12, 0, 0);
        set('legL', -0.25 * hop, 0, 0); set('legR', -0.25 * hop, 0, 0);
        set('footL', 0.25 * hop, 0, 0); set('footR', 0.25 * hop, 0, 0);
        set('tail1', 0.9, 0, 0);
        set('tail2', 0.3, 0, 0);
        break;
    }
    case 'eat': {
        const chew = Math.sin(TAU * 3.4 * t);
        out[CH.HIPS_Y] = 0.008 * chew;
        set('armL', -1.25, 0, -0.45); set('foreL', -1.45, 0, 0);
        set('armR', -1.25, 0, 0.45); set('foreR', -1.45, 0, 0);
        set('head', -0.12 + 0.10 * chew, 0, 0);
        set('torso', 0.05, 0, 0);
        set('tail1', 0, 0.14 * Math.sin(TAU * t / 2.4), 0);
        set('tail2', 0, 0.2 * Math.sin(TAU * t / 2.4 - 0.8), 0);
        out[rot('food', 2)] = 0.05 * chew;
        out[CH.VIS_FOOD] = 1;
        out[CH.VIS_AXE] = 0;
        break;
    }
    default:
        break;
    }
    return out;
}

// Ходьба, бег и ходьба с грузом: шаг с перекатом стопы, покачивание таза, скрутка корпуса, ход рук с локтями, хвост.
// Амплитуда растёт со скоростью (c.move).
function locomotion(out, set, c, kind) {
    const m = c.move;
    const mm = Math.min(m, 1.3);
    const s = Math.sin(TAU * c.gait);
    const cs = Math.cos(TAU * c.gait);
    const run = kind === 'run';
    const carry = kind === 'carry';
    const legA = (run ? 1.05 : 0.72) * mm * (carry ? 0.8 : 1) * (1 - 0.18 * c.load);
    const armA = (run ? 0.9 : 0.5) * mm;
    const legL = -s * legA;
    const legR = s * legA;
    set('legL', legL, 0, 0);
    set('legR', legR, 0, 0);
    // стопа: пятка вперёд, потом перекат на носок; в воздухе носок подтянут
    const foot = (leg) => (leg < 0 ? -leg * 0.75 : -leg * 0.35 + 0.22 * Math.min(1, mm) * Math.sin(Math.min(1, leg / 0.5) * Math.PI));
    set('footL', foot(legL), 0, 0);
    set('footR', foot(legR), 0, 0);
    // шаг: тело проседает, когда ноги широко расставлены; на беге подпрыгивает
    out[CH.HIPS_Y] = run ? 0.05 * m * (Math.abs(s) - 0.5) : -0.035 * m * (1 - Math.abs(cs));
    const sway = Math.min(m, 1);
    out[CH.HIPS_X] = 0.014 * s * sway;                             // переваливается с боку на бок
    set('hips', 0, 0.12 * s * sway, 0.07 * s * sway);
    set('torso', (run ? 0.18 + 0.2 * c.load : 0.04 + 0.16 * c.load) * sway, -0.09 * s * sway, -0.045 * s * sway);
    set('head', 0.03 * cs * sway - (run ? 0.05 : 0), -0.05 * s * sway, 0.03 * s * sway);
    set('tail1', run ? 0.25 : 0.05 * sway, 0.30 * Math.sin(TAU * c.gait - 1.0) * sway, 0);
    set('tail2', run ? 0.1 : 0, 0.42 * Math.sin(TAU * c.gait - 1.9) * sway, 0);
    if (carry) {
        // обе лапы держат охапку брёвен перед грудью
        set('armL', -1.15 + 0.04 * cs, 0, -0.35); set('foreL', -0.75, 0, 0);
        set('armR', -1.15 + 0.04 * cs, 0, 0.35); set('foreR', -0.75, 0, 0);
        out[rot('torso', 0)] = -0.10 - 0.08 * c.load + 0.02 * s * sway;
        out[CH.VIS_LOGS] = 1;
        out[CH.VIS_AXE] = 0;
    } else {
        // левая лапа машет с согнутым локтем; правая держит топор на весу
        set('armL', s * armA, 0, 0.07);
        set('foreL', -(0.22 + 0.45 * mm * (0.5 + 0.5 * cs * (run ? 1 : 0.6))), 0, 0);
        holdAxe(out, set, -0.1 * s * mm, 0.06 * cs * mm);
        if (run) { out[rot('armL', 0)] += 0.35; out[rot('armR', 0)] += -0.10; }
    }
}

/**
 * Аниматор: хранит текущее состояние, время, фазу шага и цикл рубки; каждый кадр даёт позу pose (Float32Array).
 * Переход между состояниями: замораживается поза на момент смены и плавно (0.15 с) смешивается с новой целью.
 */
export function createBeaverAnimator({ onEvent } = {}) {
    const pose = new Float32Array(CH.COUNT);
    const from = new Float32Array(CH.COUNT);
    const target = new Float32Array(CH.COUNT);
    let state = 'idle';
    let stateT = 0;
    let blendT = BLEND_S;
    let blendDur = BLEND_S;
    let gait = 0;
    let speed = 0;
    let load = 0;
    let move = 0;
    let impact = 0;
    let chopPhase = 0;
    let chopRate = 2.2;
    let chopAuto = true;
    let swinging = false;
    let pendingHits = 0;
    const ctx = { t: 0, gait: 0, move: 0, load: 0, chopP: 0, impact: 0 };

    function emit(event) { if (onEvent) onEvent(event); }
    function strike() { impact = 1; emit({ type: 'hit' }); }

    function compute() {
        ctx.t = stateT; ctx.gait = gait; ctx.move = move; ctx.load = load; ctx.chopP = chopPhase; ctx.impact = impact;
        buildPose(target, state, ctx);
    }

    function advanceChop(dt) {
        if (!chopAuto && !swinging) return;
        const p = chopPhase + dt * Math.min(chopRate, MAX_CHOP_RATE);
        if (p >= 1) {
            if (chopPhase < IMPACT_PHASE) strike();
            if (chopAuto || pendingHits > 0) {
                if (!chopAuto) pendingHits -= 1;
                chopPhase = p % 1;
                if (chopPhase >= IMPACT_PHASE) strike(); // очень длинный кадр: следующий удар тоже случился
            } else {
                swinging = false;
                chopPhase = 0;
            }
            return;
        }
        if (chopPhase < IMPACT_PHASE && p >= IMPACT_PHASE) strike();
        chopPhase = p;
    }

    compute();
    pose.set(target);

    return {
        pose,
        get state() { return state; },
        get blending() { return blendT < blendDur; },
        get chopPhase() { return chopPhase; },
        get gait() { return gait; },
        get swinging() { return swinging; },
        /** Включает состояние. Повторный вызов с тем же состоянием ничего не ломает. opts для chop: { auto, rate }. */
        setState(next, opts = {}) {
            if (!STATE_NAMES.includes(next)) throw new Error(`неизвестное состояние бобра: ${next}`);
            if (next === 'chop') {
                if (opts.auto !== undefined) chopAuto = Boolean(opts.auto);
                if (opts.rate !== undefined) chopRate = clamp(opts.rate, 0.2, MAX_CHOP_RATE);
            }
            if (next === state) return false;
            from.set(pose);
            state = next;
            stateT = 0;
            blendT = 0;
            blendDur = BLEND_S;
            chopPhase = 0;
            swinging = false;
            pendingHits = 0;
            return true;
        },
        setSpeed(mps) { speed = Math.max(0, Number(mps) || 0); },
        setLoad(v) { load = clamp(Number(v) || 0, 0, 1); },
        setChopRate(r) { chopRate = clamp(Number(r) || 2.2, 0.2, MAX_CHOP_RATE); },
        /** Один взмах по тапу: включает режим рубки (ручной) и запускает цикл; тапы во время взмаха копятся (не больше двух). */
        swing() {
            if (state !== 'chop') { this.setState('chop', { auto: false }); }
            chopAuto = false;
            if (!swinging) { swinging = true; chopPhase = 0; } else pendingHits = Math.min(pendingHits + 1, 2);
        },
        update(dt) {
            const step = clamp(Number(dt) || 0, 0, 0.1);
            stateT += step;
            blendT += step;
            move += (clamp(speed / 3.2, 0, 1.4) - move) * (1 - Math.exp(-step / 0.08));
            if (speed > 0.05) {
                const stride = state === 'run' ? 2.6 : state === 'carry' ? 1.7 : 1.9;
                gait = (gait + (speed / stride) * step) % 1;
            }
            if (state === 'chop') advanceChop(step);
            impact *= Math.exp(-step / 0.07);
            if (impact < 0.002) impact = 0;
            compute();
            if (blendT >= blendDur) {
                pose.set(target);
            } else {
                const e = smooth(blendT / blendDur);
                for (let i = 0; i < CH.COUNT; i += 1) pose[i] = from[i] + (target[i] - from[i]) * e;
            }
            return pose;
        },
    };
}
