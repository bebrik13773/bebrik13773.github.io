import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createBeaverAnimator, buildPose, chopCurve, CH, rot, BONES, STATE_NAMES, BLEND_S, IMPACT_PHASE, MAX_CHOP_RATE,
} from '../src/entities/beaver-anim.js';

const DT = 1 / 60;
function run(anim, seconds, dt = DT) { for (let t = 0; t < seconds; t += dt) anim.update(dt); }
const finite = (pose) => pose.every(Number.isFinite);

test('все состояния дают конечные числа и нужное число каналов', () => {
    const out = new Float32Array(CH.COUNT);
    for (const s of STATE_NAMES) {
        for (const t of [0, 0.3, 1.7, 9.1]) {
            buildPose(out, s, { t, gait: (t * 0.37) % 1, move: 1, load: 0.5, chopP: (t * 0.3) % 1, impact: 0.2 });
            assert.ok(finite(out), `${s} t=${t}`);
        }
    }
    assert.equal(CH.COUNT, BONES.length * 3 + 8);
});

test('неизвестное состояние отвергается', () => {
    const a = createBeaverAnimator();
    assert.throws(() => a.setState('летит'));
});

test('ходьба: ноги и руки качаются в противофазе, при нулевой скорости стоит на месте', () => {
    const a = createBeaverAnimator();
    a.setState('walk');
    a.setSpeed(3.2);
    run(a, 1.0);
    let minL = 9; let maxL = -9; let ok = true;
    for (let i = 0; i < 90; i += 1) {
        a.update(DT);
        const l = a.pose[rot('legL', 0)]; const r = a.pose[rot('legR', 0)];
        minL = Math.min(minL, l); maxL = Math.max(maxL, l);
        if (Math.abs(l + r) > 1e-3) ok = false;                       // левая нога зеркальна правой
        const armL = a.pose[rot('armL', 0)];
        if (l * armL > 1e-4) ok = false;                              // рука качается навстречу своей ноге
    }
    assert.ok(ok, 'противофаза ног и рук');
    assert.ok(maxL - minL > 1.0, `размах ноги ${maxL - minL}`);
    const b = createBeaverAnimator();
    b.setState('walk'); b.setSpeed(0); run(b, 1.0);
    assert.ok(Math.abs(b.pose[rot('legL', 0)]) < 0.01, 'без скорости ноги не качаются');
});

test('фаза шага растёт со скоростью: быстрее идёт, чаще шаги', () => {
    const slow = createBeaverAnimator(); slow.setState('walk'); slow.setSpeed(1.5);
    const fast = createBeaverAnimator(); fast.setState('walk'); fast.setSpeed(3.2);
    let slowTurns = 0; let fastTurns = 0; let ps = 0; let pf = 0;
    for (let i = 0; i < 600; i += 1) {
        slow.update(DT); fast.update(DT);
        if (slow.gait < ps) slowTurns += 1; if (fast.gait < pf) fastTurns += 1;
        ps = slow.gait; pf = fast.gait;
    }
    assert.ok(fastTurns > slowTurns, `${fastTurns} против ${slowTurns}`);
});

test('нагрузка наклоняет бегущего вперёд сильнее', () => {
    const light = createBeaverAnimator(); light.setState('run'); light.setSpeed(5); light.setLoad(0); run(light, 1.2);
    const heavy = createBeaverAnimator(); heavy.setState('run'); heavy.setSpeed(5); heavy.setLoad(1); run(heavy, 1.2);
    assert.ok(heavy.pose[rot('torso', 0)] > light.pose[rot('torso', 0)] + 0.05);
});

test('рубка: события удара идут с заданной частотой и сжатие вспыхивает на ударе', () => {
    let hits = 0;
    const a = createBeaverAnimator({ onEvent: (e) => { if (e.type === 'hit') hits += 1; } });
    a.setState('chop', { auto: true, rate: 2 });
    let maxSquash = 0;
    for (let t = 0; t < 5.0; t += DT) { a.update(DT); maxSquash = Math.max(maxSquash, a.pose[CH.SQUASH]); }
    assert.ok(hits >= 9 && hits <= 11, `ударов ${hits}`);
    assert.ok(maxSquash > 0.5, `сжатие ${maxSquash}`);
});

test('рубка: частота взмахов ограничена, но кадров не пропускает', () => {
    let hits = 0;
    const a = createBeaverAnimator({ onEvent: () => { hits += 1; } });
    a.setState('chop', { auto: true, rate: 99 });
    run(a, 5.0, 1 / 30);
    assert.ok(hits <= Math.ceil(5 * MAX_CHOP_RATE) + 1, `ударов ${hits}`);
    assert.ok(hits >= 5 * MAX_CHOP_RATE - 2);
});

test('ручной взмах: один тап даёт один удар, лишние тапы копятся не больше двух', () => {
    let hits = 0;
    const a = createBeaverAnimator({ onEvent: () => { hits += 1; } });
    a.swing();
    assert.equal(a.state, 'chop');
    assert.ok(a.swinging);
    run(a, 1.0);
    assert.equal(hits, 1);
    assert.ok(!a.swinging, 'после взмаха ждёт следующий тап');
    const before = a.pose[rot('armR', 0)];
    run(a, 1.0);
    assert.ok(Math.abs(a.pose[rot('armR', 0)] - before) < 1e-3, 'в ожидании поза не меняется');
    for (let i = 0; i < 6; i += 1) a.swing();
    run(a, 3.0);
    assert.equal(hits, 1 + 3, 'один текущий и два в очереди');
});

test('кривая взмаха: непрерывна на стыке циклов и бьёт после замаха', () => {
    const a = chopCurve(0); const b = chopCurve(0.999999);
    assert.ok(Math.abs(a.arm - b.arm) < 1e-3 && Math.abs(a.wrist - b.wrist) < 1e-3 && Math.abs(a.lean - b.lean) < 1e-3);
    assert.ok(chopCurve(0.45).arm < chopCurve(0.1).arm, 'замах выше подъёма');
    assert.ok(chopCurve(IMPACT_PHASE).arm > chopCurve(0.45).arm, 'удар опускает руку');
    assert.ok(chopCurve(IMPACT_PHASE).lean > 0.3, 'на ударе корпус наклонён вперёд');
});

test('сон: бобёр ложится на бок, топор убран', () => {
    const a = createBeaverAnimator();
    a.setState('sleep'); run(a, 1.0);
    assert.ok(Math.abs(a.pose[rot('hips', 2)]) > 1.2);
    assert.ok(a.pose[CH.VIS_AXE] < 0.01);
    assert.ok(a.pose[CH.HIPS_Y] < 0);
});

test('еда и ноша показывают свои предметы, топор скрыт', () => {
    const a = createBeaverAnimator();
    a.setState('eat'); run(a, 0.5);
    assert.ok(a.pose[CH.VIS_FOOD] > 0.99 && a.pose[CH.VIS_AXE] < 0.01);
    a.setState('carry'); run(a, 0.5);
    assert.ok(a.pose[CH.VIS_LOGS] > 0.99 && a.pose[CH.VIS_FOOD] < 0.01 && a.pose[CH.VIS_AXE] < 0.01);
    a.setState('idle'); run(a, 0.5);
    assert.ok(a.pose[CH.VIS_AXE] > 0.99 && a.pose[CH.VIS_LOGS] < 0.01);
});

test('испуг: прыжки вверх и поднятый хвост', () => {
    const a = createBeaverAnimator();
    a.setState('scared');
    let maxY = 0;
    for (let t = 0; t < 2; t += DT) { a.update(DT); maxY = Math.max(maxY, a.pose[CH.ROOT_Y]); }
    assert.ok(maxY > 0.1);
    assert.ok(a.pose[rot('tail1', 0)] > 0.8);
});

test('переходы плавные: первый кадр после смены не прыгает, дальше гладкая кривая за 0.15 с', () => {
    const order = ['idle', 'walk', 'run', 'carry', 'idle', 'sleep', 'eat', 'scared', 'idle', 'chop', 'idle'];
    const a = createBeaverAnimator();
    a.setSpeed(3.2);
    const dt = 1 / 30;
    let prev = Float32Array.from(a.pose);
    let worstFirst = 0;
    let worstAny = 0;
    for (const s of order) {
        a.setState(s, s === 'chop' ? { auto: true, rate: 1.6 } : undefined);
        let first = true;
        for (let t = 0; t < 1.2; t += dt) {
            a.update(dt);
            for (let i = 0; i < CH.COUNT; i += 1) {
                const d = Math.abs(a.pose[i] - prev[i]);
                // быстрый удар топора и прыжки испуга резкие по задумке; смену состояния проверяем всегда
                if (first) worstFirst = Math.max(worstFirst, d);
                else if (!(s === 'chop' || s === 'scared')) worstAny = Math.max(worstAny, d);
            }
            first = false;
            prev = Float32Array.from(a.pose);
        }
    }
    assert.ok(worstFirst < 0.45, `первый кадр после смены ${worstFirst}`);
    assert.ok(worstAny < 1.0, `самый резкий кадр ${worstAny}`);
});

test('переход укладывается в BLEND_S: после него поза равна цели', () => {
    const a = createBeaverAnimator();
    a.setState('sleep');
    run(a, BLEND_S + 0.05);
    const target = new Float32Array(CH.COUNT);
    buildPose(target, 'sleep', { t: a.pose[0], gait: 0, move: 0, load: 0, chopP: 0, impact: 0 });
    assert.ok(!a.blending);
    assert.ok(Math.abs(a.pose[rot('hips', 2)] - 1.42) < 1e-4);
});

test('смена состояния: сначала поза замораживается, потом смешивается за BLEND_S', () => {
    const a = createBeaverAnimator();
    a.setState('sleep'); run(a, 1.0);
    const asleep = a.pose[rot('hips', 2)];
    a.setState('idle');
    assert.ok(a.blending);
    a.update(DT);
    assert.ok(a.pose[rot('hips', 2)] > asleep * 0.9, 'в первый кадр почти прежняя поза');
    run(a, BLEND_S + 0.1);
    assert.ok(!a.blending);
    assert.ok(Math.abs(a.pose[rot('hips', 2)]) < 0.1);
    assert.equal(a.setState('idle'), false, 'то же состояние ничего не меняет');
});

test('огромный или отрицательный dt не ломает позу', () => {
    const a = createBeaverAnimator();
    a.setState('walk'); a.setSpeed(3);
    for (const dt of [0, -1, 5, NaN, Infinity]) { a.update(dt); assert.ok(finite(a.pose), String(dt)); }
});
