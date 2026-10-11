import test from 'node:test';
import assert from 'node:assert/strict';
import { heroSpeed, moveAllowance, checkTrack, HERO } from '../src/shared/formulas.js';
import { createGestures } from '../src/core/gestures.js';
import { createCameraRig, resolveCameraDistance } from '../src/core/camera-rig.js';
import { createPositionTrack } from '../src/core/track.js';
import { INPUT, CAMERA } from '../src/config.js';

test('формула скорости: налегке, с грузом, дорога, сапоги', () => {
    assert.equal(heroSpeed(), 3.2);
    assert.ok(Math.abs(heroSpeed({ load: 40, capacity: 40 }) - 3.2 * 0.45) < 1e-9);
    const half = 3.2 * (1 - 0.55 * Math.pow(0.5, 1.2));
    assert.ok(Math.abs(heroSpeed({ load: 20, capacity: 40 }) - half) < 1e-9);
    assert.ok(Math.abs(heroSpeed({ road: 3 }) - 3.2 * 1.45) < 1e-9);
    assert.ok(Math.abs(heroSpeed({ boots: true }) - 3.2 * 1.1) < 1e-9);
    assert.ok(heroSpeed({ load: 999, capacity: 40 }) >= 3.2 * 0.45 - 1e-9, 'перегруз не замедляет сильнее полного рюкзака');
    assert.ok(Math.abs(heroSpeed({ weather: 0.8 }) - 3.2 * 0.8) < 1e-9);
});

test('допуск сервера: vMax × dt × 1.35 + 3 м, проверка журнала позиций', () => {
    assert.ok(Math.abs(moveAllowance(3.2, 1) - (3.2 * 1.35 + 3)) < 1e-9);
    const v = () => 3.2;
    const honest = [0, 1, 2, 3, 4].map((i) => ({ t: i, x: i * 3.2, z: 0 }));
    assert.equal(checkTrack(honest, v).ok, true);
    const cheat = [{ t: 0, x: 0, z: 0 }, { t: 1, x: 40, z: 0 }];
    const r = checkTrack(cheat, v);
    assert.equal(r.ok, false);
    assert.equal(r.bad[0].i, 1);
    assert.equal(HERO.walkSpeed, 3.2);
});

function rec() {
    const ev = { taps: [], orbit: [], zoom: [] };
    const g = createGestures({ onTap: (t) => ev.taps.push(t), onOrbit: (a, b) => ev.orbit.push([a, b]), onZoom: (f) => ev.zoom.push(f) });
    return { g, ev };
}

test('жесты: короткий тап даёт onTap, долгое нажатие и сдвиг нет', () => {
    const { g, ev } = rec();
    g.down(1, 100, 100, 0); g.up(1, 102, 101, 120);
    assert.equal(ev.taps.length, 1);
    g.down(1, 100, 100, 1000); g.up(1, 100, 100, 1000 + INPUT.tapMs + 50);
    assert.equal(ev.taps.length, 1, 'слишком долго');
    g.down(1, 100, 100, 2000); g.move(1, 100 + INPUT.tapPx + 5, 100); g.up(1, 120, 100, 2100);
    assert.equal(ev.taps.length, 1, 'палец сдвинулся');
    assert.equal(ev.orbit.length, 0, 'один палец камеру не крутит');
});

test('жесты: щипок = зум, после двух пальцев тапа нет', () => {
    const { g, ev } = rec();
    g.down(1, 100, 300, 0); g.down(2, 200, 300, 10);
    g.move(2, 260, 300); g.move(2, 320, 300); // пальцы расходятся: ближе
    assert.ok(ev.zoom.length >= 1);
    assert.ok(ev.zoom.every((f) => f < 1), 'разведение пальцев уменьшает дальность камеры');
    g.up(2, 320, 300, 100); g.up(1, 100, 300, 110);
    assert.equal(ev.taps.length, 0);
    const b = rec();
    b.g.down(1, 100, 300, 0); b.g.down(2, 300, 300, 10); b.g.move(2, 200, 300);
    assert.ok(b.ev.zoom.some((f) => f > 1), 'сведение пальцев отдаляет');
});

test('жесты: поворот двух пальцев крутит камеру, мелкая дрожь нет', () => {
    const a = rec();
    a.g.down(1, 100, 300, 0); a.g.down(2, 200, 300, 5);
    a.g.move(2, 201, 301);
    assert.equal(a.ev.orbit.length, 0, 'дрожь в пределах порогов игнорируется');
    const b = rec();
    b.g.down(1, 150, 300, 0); b.g.down(2, 250, 300, 5);
    for (let i = 1; i <= 8; i += 1) { const ang = i * 0.1; b.g.move(2, 150 + 100 * Math.cos(ang), 300 + 100 * Math.sin(ang)); }
    assert.ok(b.ev.orbit.length > 0);
    assert.ok(b.ev.orbit.reduce((s, [d]) => s + d, 0) > 0.3, 'по часовой стрелке yaw растёт');
});

test('жесты: мышь, правая кнопка вращает, колесо зумит, левая даёт тап', () => {
    const { g, ev } = rec();
    g.down(1, 50, 50, 0, 'mouse', 2); g.move(1, 100, 80); g.up(1, 100, 80, 90, 'mouse');
    assert.equal(ev.taps.length, 0);
    assert.ok(Math.abs(ev.orbit[0][0] + 50 * INPUT.orbitYawPerPx) < 1e-9);
    g.wheel(100);
    assert.ok(ev.zoom[0] > 1);
    g.wheel(-100);
    assert.ok(ev.zoom[1] < 1);
    g.down(2, 10, 10, 1000, 'mouse', 0); g.up(2, 10, 10, 1050);
    assert.equal(ev.taps.length, 1);
});

test('камера: наклон 20–70°, зум 6–18, пружина догоняет цель', () => {
    const rig = createCameraRig();
    rig.orbit(0, 5); assert.ok(Math.abs(rig.goal.pitch - CAMERA.maxPitchDeg * Math.PI / 180) < 1e-9);
    rig.orbit(0, -9); assert.ok(Math.abs(rig.goal.pitch - CAMERA.minPitchDeg * Math.PI / 180) < 1e-9);
    rig.zoom(100); assert.equal(rig.goal.dist, CAMERA.maxDist);
    rig.zoom(0.001); assert.equal(rig.goal.dist, CAMERA.minDist);
    for (let i = 0; i < 120; i += 1) rig.step(1 / 30);
    assert.ok(Math.abs(rig.dist - CAMERA.minDist) < 0.01);
    rig.setDefaultDist(12);
    assert.equal(rig.goal.dist, CAMERA.minDist, 'игрок уже зумил, экран не перебивает его выбор');
    const fresh = createCameraRig();
    fresh.setDefaultDist(12); assert.equal(fresh.goal.dist, 12);
});

test('камера: не уходит под землю за холмом', () => {
    const ground = (x) => (x > 3 && x < 6 ? 5 : 0); // холм между героем и камерой
    const dir = { x: 1, y: 0.2, z: 0 };
    const d = resolveCameraDistance({ x: 0, y: 1.2, z: 0 }, dir, 12, (x) => ground(x), 0.8);
    assert.ok(d < 12 && d >= 2.5, `камера приблизилась: ${d}`);
    assert.equal(resolveCameraDistance({ x: 0, y: 20, z: 0 }, { x: 1, y: 0, z: 0 }, 12, () => 0, 0.8), 12);
});

test('журнал позиций: раз в секунду, последняя точка остаётся опорной', () => {
    const t = createPositionTrack({ intervalMs: 1000, max: 5 });
    assert.equal(t.sample(0, 0, 0), true);
    assert.equal(t.sample(400, 1, 0), false);
    assert.equal(t.sample(1000, 3, 0), true);
    assert.equal(t.sample(2000, 6, 0), true);
    const d = t.drain();
    assert.equal(d.length, 3);
    assert.equal(t.size, 1);
    assert.deepEqual(t.peek()[0], d[2]);
    for (let i = 0; i < 20; i += 1) t.sample(3000 + i * 1000, i, 0);
    assert.ok(t.size <= 5);
});
