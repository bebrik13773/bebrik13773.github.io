import * as THREE from '../three.js';
import { WORLD, CAMERA, INPUT, MOVE } from '../config.js';
import { KIND } from '../shared/worldgen.js';
import { heroSpeed } from '../shared/formulas.js';
import { createModels } from './models.js';
import { createChunkManager } from './chunks.js';
import { findDemoSpawn } from './spawn.js';
import { createPondAndDam } from './village-props.js';
import { createWalkGrid } from './terrain-nav.js';
import { createNav } from './nav.js';
import { pickObject } from './picking.js';
import { createBeaver } from '../entities/beaver.js';
import { createDrops } from './drops.js';
import { createHero } from '../entities/hero.js';
import { createCameraRig, resolveCameraDistance } from '../core/camera-rig.js';
import { createPositionTrack } from '../core/track.js';

// Сцена мира (ДЛ-06, управление и камера ДЛ-08): бесконечный лес из чанков, вода, свет, туман, герой и камера от третьего лица.
// Герой это 3D-бобёр (ДЛ-07). Логика героя (путь, препятствия, приказы) живёт в entities/hero.js, камера в core/camera-rig.js,
// ввод в core/input.js; здесь они собираются вместе, плюс выбор объекта под пальцем и метка цели.
const SKY = 0xbfe3f2;
const WATER_COLOR = 0x3d8fb8;
const MARK_GROUND = 0xffffff;
const MARK_PARTIAL = 0xffb36b; // дойдём только до ближайшей достижимой точки
const MARK_OBJECT = 0xf2b441;

/** start (необязательно): { x, z, clearings: [{x,z,r}], pond } с сервера; без него временная точка (оффлайн-режим). */
export function createWorldScene({ wg, start = null, onEvent = () => {} }) {
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(SKY);
    scene.fog = new THREE.Fog(SKY, 20, 160);

    const camera = new THREE.PerspectiveCamera(60, 1, 0.3, 300);

    const hemi = new THREE.HemisphereLight(0xe6f5ff, 0x5f7a45, 1.15);
    scene.add(hemi);
    const sun = new THREE.DirectionalLight(0xfff1d0, 1.2);
    sun.castShadow = false;
    sun.shadow.mapSize.set(1024, 1024);
    const sc = sun.shadow.camera;
    sc.left = -45; sc.right = 45; sc.top = 45; sc.bottom = -45; sc.near = 1; sc.far = 220;
    scene.add(sun);
    scene.add(sun.target);

    const models = createModels();
    const chunks = createChunkManager({ scene, wg, models });
    const spawn = start ? { x: start.x, z: start.z } : findDemoSpawn(wg);
    if (start && Array.isArray(start.clearings) && start.clearings.length) {
        start.clearings.forEach((c) => chunks.addClearing(c.x, c.z, c.r)); // поляна и место пруда с сервера (ДЛ-10)
    } else {
        chunks.addClearing(spawn.x, spawn.z, 12); // без сервера: временная поляна
    }

    if (start && Array.isArray(start.felled)) start.felled.forEach((id) => chunks.fell(id)); // уже срубленное (пока без потока изменений ДЛ-21)

    // Вода: одна плоскость на уровне воды, едет за героем (озёра и реки уже «вырезаны» рельефом).
    const water = new THREE.Mesh(
        new THREE.PlaneGeometry(1, 1).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({ color: WATER_COLOR, transparent: true, opacity: 0.74, depthWrite: false }),
    );
    water.position.y = WORLD.waterLevelM;
    water.renderOrder = 1;
    scene.add(water);

    // Герой: 3D-бобёр. Поворот лицом по ходу движения плавный, анимация идёт от реального кадра, а не от шага логики.
    const hero = new THREE.Group();
    const beaver = createBeaver({ detail: 'high' });
    hero.add(beaver.group);
    const blob = new THREE.Mesh(
        new THREE.CircleGeometry(0.7, 16).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({ color: 0x000000, transparent: true, opacity: 0.35, depthWrite: false }),
    );
    blob.position.y = 0.04;
    hero.add(blob);
    scene.add(hero);

    let preset = null;

    // Пруд и плотина игрока (ДЛ-10). Высота земли зависит от качества рельефа, поэтому при смене качества строим заново.
    let pondProps = null;
    function buildPond() {
        if (!start || !start.pond) return;
        if (pondProps) pondProps.dispose();
        pondProps = createPondAndDam({ pond: start.pond, groundY: (x, z) => groundY(x, z) });
        scene.add(pondProps.group);
    }
    buildPond();
    const drops = createDrops({ scene, groundY: (x, z) => groundY(x, z) });
    if (start && Array.isArray(start.drops)) drops.add(start.drops);
    const rig = createCameraRig();
    const track = createPositionTrack();
    const size = { w: 390, h: 800 };           // размер холста в CSS-пикселях (для выбора объекта)
    let shownDist = rig.dist;                    // дальность камеры с учётом рельефа (сначала быстро ближе, потом плавно обратно)
    let sleeping = false;                        // бобёр спит: тапы и клавиши не двигают (ДЛ-12)
    let stateOverride = null;                    // ручное состояние для отладки и тестов (null = по движению и работе)
    let lastRender = 0;
    let clock = 0;
    const input = { x: 0, z: 0 };                // движение клавишами: x вправо, z вперёд
    const dynamicTargets = new Map();            // объекты, которые добавят следующие пункты (NPC, предметы, постройки, помощники)
    let speedMods = { load: 0, capacity: 40, road: 0, boots: false, weather: 1 };
    const pickKinds = new Set([KIND.TREE]);       // что можно выбрать тапом (камни и руда придут вместе с киркой)
    let sized = false;
    let workHits = 0;                             // сколько тапов по объекту в режиме работы (ДЛ-11 заменит настоящей рубкой)

    /** Высота земли под героем: билинейно по сетке рельефа текущего качества (совпадает с тем, что нарисовано). */
    function groundY(x, z) {
        const seg = preset ? preset.terrainSeg : 16;
        const step = 320 / seg; // дм
        const gx = (x * 10) / step;
        const gz = (z * 10) / step;
        const x0 = Math.floor(gx);
        const z0 = Math.floor(gz);
        const tx = gx - x0;
        const tz = gz - z0;
        const h = (i, j) => wg.heightAtDm(Math.round(i * step), Math.round(j * step));
        const a = h(x0, z0) * (1 - tx) + h(x0 + 1, z0) * tx;
        const b = h(x0, z0 + 1) * (1 - tx) + h(x0 + 1, z0 + 1) * tx;
        return (a * (1 - tz) + b * tz) / 10;
    }

    const isWater = (x, z) => groundY(x, z) < WORLD.waterLevelM;

    // Навигация и контроллер героя (ДЛ-08): проходимость земли, препятствия из чанков, путь A*, приказы.
    const walk = createWalkGrid({ wg });
    const nav = createNav({ walk, obstacles: chunks });
    const ctl = createHero({ nav, x: spawn.x, z: spawn.z, vMax: heroSpeed(speedMods) });
    ctl.on('arrive', (e) => { marker.visible = false; onEvent({ type: 'arrive', ...e }); });
    ctl.on('blocked', (e) => { marker.visible = false; onEvent({ type: 'blocked', ...e }); });
    ctl.on('ready', (obj) => onEvent({ type: 'ready', obj }));

    // Метка цели: кольцо на земле (белое = точка, оранжевое = дойдём только до ближайшей, золотое = объект).
    const marker = new THREE.Mesh(
        new THREE.RingGeometry(0.34, 0.5, 28).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({ color: MARK_GROUND, transparent: true, opacity: 0.9, depthWrite: false }),
    );
    marker.renderOrder = 3;
    marker.visible = false;
    scene.add(marker);
    function showMarker(x, z, color, radius = 0.5) {
        marker.position.set(x, groundY(x, z) + 0.12, z);
        marker.scale.setScalar(radius / 0.5);
        marker.material.color.setHex(color);
        marker.userData.base = radius / 0.5;
        marker.visible = true;
    }

    function placeCamera(alpha, frameDt) {
        const hx = ctl.px + (ctl.x - ctl.px) * alpha;
        const hz = ctl.pz + (ctl.z - ctl.pz) * alpha;
        const hy = groundY(hx, hz);
        hero.position.set(hx, hy, hz);
        rig.step(frameDt);
        const cp = Math.cos(rig.pitch);
        const dir = { x: Math.sin(rig.yaw) * cp, y: Math.sin(rig.pitch), z: Math.cos(rig.yaw) * cp };
        const focus = { x: hx, y: hy + CAMERA.focusHeight, z: hz };
        // холм между героем и камерой: приближаемся сразу, отъезжаем плавно (без дёргания)
        const free = resolveCameraDistance(focus, dir, rig.dist, groundY, CAMERA.minClearance);
        if (free < shownDist) shownDist = free;
        else shownDist += (free - shownDist) * (1 - Math.exp(-4 * frameDt));
        const cxm = focus.x + dir.x * shownDist;
        const czm = focus.z + dir.z * shownDist;
        const cy = Math.max(focus.y + dir.y * shownDist, groundY(cxm, czm) + CAMERA.minClearance);
        camera.position.set(cxm, cy, czm);
        camera.lookAt(focus.x, focus.y, focus.z);
        sun.position.set(hx + 40, hy + 70, hz + 25);
        sun.target.position.set(hx, hy, hz);
        sun.target.updateMatrixWorld();
        water.position.x = hx;
        water.position.z = hz;
    }

    function teleport(x, z) {
        ctl.teleport(x, z);
        marker.visible = false;
        chunks.setCenter(x, z);
    }

    /** Состояние бобра по движению: стоит, идёт или бежит (с небольшим гистерезисом). */
    function autoState() {
        const v = ctl.moveSpeed;
        const cur = beaver.state;
        if (cur === 'idle') return v > 0.4 ? (v > 4.6 ? 'run' : 'walk') : 'idle';
        if (v < 0.15) return 'idle';
        return v > 4.6 ? 'run' : 'walk';
    }

    // ── Выбор объекта и земли под пальцем ───────────────────────────────
    const _v = new THREE.Vector3();
    function project(x, y, z) {
        _v.set(x, y, z).applyMatrix4(camera.matrixWorldInverse);
        if (_v.z > -camera.near) return null; // за камерой
        _v.applyMatrix4(camera.projectionMatrix);
        return { sx: (_v.x * 0.5 + 0.5) * size.w, sy: (-_v.y * 0.5 + 0.5) * size.h };
    }
    function candidates() {
        const list = [];
        chunks.forEachInRange(ctl.x, ctl.z, 70, (o) => {
            if (!pickKinds.has(o.kind)) return;
            list.push({ id: o.id, kind: 'tree', x: o.x, y: o.y, z: o.z, h: o.h, r: o.r, pickR: INPUT.pickTreeWorldR });
        });
        for (const d of dynamicTargets.values()) list.push(d);
        return list;
    }
    /** Объект под точкой экрана (CSS-пиксели относительно холста) или null. */
    function pickAt(px, py) {
        camera.updateMatrixWorld();
        return pickObject({ candidates: candidates(), project, cam: camera.position, px, py, viewH: size.h, fov: (camera.fov * Math.PI) / 180 });
    }

    function pickGround(ndcX, ndcY) {
        camera.updateMatrixWorld();
        const origin = camera.position;
        const dir = new THREE.Vector3(ndcX, ndcY, 0.5).unproject(camera).sub(origin).normalize();
        let prevT = 0;
        for (let t = 1; t <= 300; t += 1) {
            const px = origin.x + dir.x * t;
            const py = origin.y + dir.y * t;
            const pz = origin.z + dir.z * t;
            if (py <= groundY(px, pz)) {
                let lo = prevT;
                let hi = t;
                for (let i = 0; i < 8; i += 1) {
                    const mid = (lo + hi) / 2;
                    const my = origin.y + dir.y * mid;
                    if (my <= groundY(origin.x + dir.x * mid, origin.z + dir.z * mid)) hi = mid; else lo = mid;
                }
                const tt = (lo + hi) / 2;
                return { x: origin.x + dir.x * tt, z: origin.z + dir.z * tt };
            }
            prevT = t;
        }
        return null;
    }

    /**
     * Тап по экрану (px, py в CSS-пикселях относительно холста). Приоритет: объект (дерево и др.), затем земля.
     * Возвращает описание для интерфейса и тестов.
     */
    function handleTap(px, py) {
        if (sleeping) { onEvent({ type: 'sleepingTap' }); return { type: 'sleeping' }; }
        const obj = pickAt(px, py);
        if (obj) {
            if (ctl.mode === 'work' && ctl.obj && ctl.obj.id === obj.id) {
                workHits += 1;
                beaver.setState('chop', { auto: false });
                beaver.swing();
                onEvent({ type: 'workTap', obj });
                return { type: 'workTap', id: obj.id };
            }
            const r = ctl.interact(obj);
            if (!r.ok) { onEvent({ type: 'unreachable', obj }); return { type: 'unreachable', id: obj.id }; }
            showMarker(obj.x, obj.z, MARK_OBJECT, obj.r + 0.45);
            onEvent({ type: 'interact', obj });
            return { type: 'interact', id: obj.id, kind: obj.kind, partial: r.partial };
        }
        const hit = pickGround((px / size.w) * 2 - 1, -((py / size.h) * 2 - 1));
        if (!hit) return { type: 'none' };
        ctl.cancelWork();
        const r = ctl.moveTo(hit.x, hit.z);
        if (!r.ok) { marker.visible = false; return { type: 'ground', ok: false, x: hit.x, z: hit.z }; }
        showMarker(hit.x, hit.z, r.partial ? MARK_PARTIAL : MARK_GROUND);
        return { type: 'ground', ok: true, partial: r.partial, x: hit.x, z: hit.z };
    }

    return {
        scene,
        camera,
        hero,
        chunks,
        ctl,
        nav,
        walk,
        rig,
        track,
        get treeCount() { return chunks.treeCount; },
        get heroPos() { return { x: ctl.x, z: ctl.z }; },
        get cameraYaw() { return rig.yaw; },
        get workHits() { return workHits; },
        beaver,
        /** Ручное состояние бобра (для отладки и тестов); null возвращает управление по движению и работе. */
        setBeaverState(name, opts) {
            stateOverride = name || null;
            if (name) beaver.setState(name, opts);
        },
        /** Ручное проигрывание состояния (еда): держится, пока не вызван releaseBeaver. */
        playBeaver(name) { stateOverride = name; beaver.setState(name, { auto: false }); },
        releaseBeaver() { stateOverride = null; beaver.setState(autoState()); },
        /** Сон в лесу (ДЛ-12): бобёр останавливается и ложится, ввод игнорируется до пробуждения. */
        setSleeping(v) {
            sleeping = Boolean(v);
            if (sleeping) { ctl.stop(); marker.visible = false; stateOverride = 'sleep'; beaver.setState('sleep', { auto: false }); }
            else { stateOverride = null; beaver.setState(autoState()); }
        },
        get sleeping() { return sleeping; },
        groundY,
        drops,
        /** Верхушка дерева по id (для шкалы прочности): { x, y, z } или null, если чанк не загружен. */
        treeTop(id) {
            const parsed = id.split(':').map(Number);
            const c = chunks.chunk(parsed[0], parsed[1]);
            const rec = c && c.data.obs[parsed[2]];
            return rec ? { x: rec.x, y: rec.y + rec.h, z: rec.z } : null;
        },
        isWater,
        /** Пруд и плотина (для отладки и тестов): число объектов и позиция воды, null без сервера. */
        pondInfo() { return pondProps ? { children: pondProps.group.children.length, x: pondProps.group.children[0].position.x, z: pondProps.group.children[0].position.z } : null; },
        teleport,
        handleTap,
        pickAt,
        /** Экранные координаты (CSS-пиксели) точки мира; null, если за камерой. */
        projectToScreen: project,
        /** Добавить объект для выбора пальцем: { id, kind, x, y, z, h, r, pickR } (NPC, предметы, постройки, помощники). */
        addTarget(t) { dynamicTargets.set(t.id, t); },
        removeTarget(id) { dynamicTargets.delete(id); },
        setTarget(x, z) {
            if (x === null) { ctl.stop(); marker.visible = false; return { ok: true }; }
            const r = ctl.moveTo(x, z);
            if (r.ok) showMarker(x, z, r.partial ? MARK_PARTIAL : MARK_GROUND);
            return r;
        },
        /** Подойти к объекту и начать работу (то же, что тап по нему). */
        interact(obj) {
            const r = ctl.interact(obj);
            if (r.ok) showMarker(obj.x, obj.z, MARK_OBJECT, (obj.r || 0.5) + 0.45);
            return r;
        },
        setMoveInput(x, z) { input.x = x; input.z = z; },
        rotateCamera(dYaw) { rig.orbit(dYaw, 0); },
        orbitCamera(dYaw, dPitch) { rig.orbit(dYaw, dPitch); },
        zoomCamera(factor) { rig.zoom(factor); },
        /** Груз, дорога, сапоги, погода: пересчитывает максимальную скорость бобра (формула общая с сервером). */
        setSpeedMods(next) {
            speedMods = { ...speedMods, ...next };
            ctl.setSpeedLimit(heroSpeed(speedMods));
        },
        get speedLimit() { return ctl.vMax; },
        update(dt) {
            clock += dt;
            ctl.setInput(sleeping ? 0 : input.x, sleeping ? 0 : input.z, rig.yaw);
            ctl.update(dt);
            chunks.setCenter(ctl.x, ctl.z);
            track.sample(performance.now(), ctl.x, ctl.z);
        },
        render(renderer, alpha) {
            chunks.pump();
            // анимация бобра: по реальному времени кадра (при паузе вкладки шаг ограничен)
            const now = performance.now();
            const frameDt = lastRender ? Math.min(0.1, (now - lastRender) / 1000) : 0;
            lastRender = now;
            placeCamera(alpha, frameDt);
            let turn = ctl.yaw - ctl.pyaw;
            turn = Math.atan2(Math.sin(turn), Math.cos(turn));
            beaver.group.rotation.y = ctl.pyaw + turn * alpha;
            beaver.setSpeed(ctl.moveSpeed);
            if (!stateOverride) {
                if (ctl.mode === 'work') { if (beaver.state !== 'chop') beaver.setState('chop', { auto: false }); }
                else if (beaver.state === 'chop' || !['carry', 'sleep', 'scared', 'eat'].includes(beaver.state)) beaver.setState(autoState());
            }
            beaver.update(frameDt);
            beaver.setOutlineWidth(0.02 * Math.min(2.4, Math.max(0.85, rig.dist / 9)));
            water.material.color.setHex(WATER_COLOR).offsetHSL(0, 0, Math.sin(clock * 0.8) * 0.015);
            if (marker.visible) {
                if (ctl.mode === 'idle') marker.visible = false;
                else {
                    const pulse = 1 + Math.sin(clock * 7) * 0.08;
                    marker.scale.setScalar((marker.userData.base || 1) * pulse);
                }
            }
            renderer.render(scene, camera);
        },
        resize(width, height) {
            const aspect = width / Math.max(1, height);
            camera.aspect = aspect;
            // в портрете горизонтальный обзор узкий: шире угол и дальше камера, чтобы кадр не был «в упор»
            const portrait = Math.max(0, 1 - aspect);
            camera.fov = 60 + portrait * 24;
            size.w = width;
            size.h = height;
            const firstSize = !sized;
            sized = true;
            rig.setDefaultDist(CAMERA.defaultDist - 1 + portrait * 3); // портрет: чуть дальше; ближе/дальше пальцами
            if (firstSize) { rig.snap(); shownDist = rig.dist; }
            camera.updateProjectionMatrix();
        },
        pickGround,
        /** Пресет качества: дальность, туман, плотность леса, рельеф, тени. */
        applyQuality(next) {
            preset = next;
            scene.fog.far = next.drawDistance;
            scene.fog.near = Math.max(10, next.drawDistance * 0.15);
            camera.far = next.drawDistance * 1.25;
            camera.updateProjectionMatrix();
            sun.castShadow = next.shadows === 'real';
            blob.visible = next.shadows === 'blob';
            beaver.setDetail(next.beaverDetail);
            const bodyMesh = beaver.group.getObjectByName('beaver-body');
            if (bodyMesh) bodyMesh.castShadow = next.shadows === 'real';
            const size = next.drawChunks * 32 * 2 + 160;
            water.scale.set(size, 1, size);
            chunks.applyPreset(next);
            chunks.setCenter(ctl.x, ctl.z);
            buildPond();
            // материалы пересоберутся под новое состояние теней
            chunks.material.needsUpdate = true;
            beaver.group.traverse((o) => { if (o.material) o.material.needsUpdate = true; });
        },
        dispose() {
            drops.dispose();
            beaver.dispose();
            chunks.dispose();
            scene.traverse((obj) => {
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material && obj.material !== chunks.material) obj.material.dispose();
            });
        },
    };
}
