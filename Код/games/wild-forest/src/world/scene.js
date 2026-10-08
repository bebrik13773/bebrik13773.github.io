import * as THREE from '../three.js';
import { WORLD } from '../config.js';
import { createModels } from './models.js';
import { createChunkManager } from './chunks.js';
import { findDemoSpawn } from './spawn.js';

// Сцена мира (ДЛ-06): бесконечный лес из чанков, вода, свет, туман, герой-маркер и камера от третьего лица.
// Герой пока «капсула» (3D-бобёр приходит в ДЛ-07), управление временное (core/demo-input.js, полноценное в ДЛ-08).
const SKY = 0xbfe3f2;
const WATER_COLOR = 0x3d8fb8;

export function createWorldScene({ wg }) {
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
    const spawn = findDemoSpawn(wg);
    chunks.addClearing(spawn.x, spawn.z, 12); // временная поляна: настоящая приходит с сервером (ДЛ-10)

    // Вода: одна плоскость на уровне воды, едет за героем (озёра и реки уже «вырезаны» рельефом).
    const water = new THREE.Mesh(
        new THREE.PlaneGeometry(1, 1).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({ color: WATER_COLOR, transparent: true, opacity: 0.74, depthWrite: false }),
    );
    water.position.y = WORLD.waterLevelM;
    water.renderOrder = 1;
    scene.add(water);

    // Герой-маркер.
    const hero = new THREE.Group();
    const body = new THREE.Mesh(new THREE.CapsuleGeometry(0.4, 0.7, 4, 8), new THREE.MeshLambertMaterial({ color: 0x9a6a3a }));
    body.position.y = 0.75;
    hero.add(body);
    const blob = new THREE.Mesh(
        new THREE.CircleGeometry(0.7, 16).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({ color: 0x000000, transparent: true, opacity: 0.35, depthWrite: false }),
    );
    blob.position.y = 0.04;
    hero.add(blob);
    scene.add(hero);

    let preset = null;
    const pos = { x: spawn.x, z: spawn.z, px: spawn.x, pz: spawn.z }; // текущее и прошлое положение (для интерполяции кадров)
    const cam = { yaw: 0.8, pitch: 0.8, dist: 11 };
    let target = null; // точка, куда идёт герой по тапу
    const input = { x: 0, z: 0 }; // движение клавишами: x вправо, z вперёд
    let clock = 0;

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

    function placeCamera(alpha) {
        const hx = pos.px + (pos.x - pos.px) * alpha;
        const hz = pos.pz + (pos.z - pos.pz) * alpha;
        const hy = groundY(hx, hz);
        hero.position.set(hx, hy, hz);
        const cp = Math.cos(cam.pitch);
        const cxm = hx + Math.sin(cam.yaw) * cp * cam.dist;
        const czm = hz + Math.cos(cam.yaw) * cp * cam.dist;
        let cy = hy + 1.2 + Math.sin(cam.pitch) * cam.dist;
        cy = Math.max(cy, groundY(cxm, czm) + 1.5); // камера не уходит под землю
        camera.position.set(cxm, cy, czm);
        camera.lookAt(hx, hy + 1.2, hz);
        sun.position.set(hx + 40, hy + 70, hz + 25);
        sun.target.position.set(hx, hy, hz);
        sun.target.updateMatrixWorld();
        water.position.x = hx;
        water.position.z = hz;
    }

    function teleport(x, z) {
        pos.x = x;
        pos.px = x;
        pos.z = z;
        pos.pz = z;
        target = null;
        chunks.setCenter(x, z);
    }

    function stepHero(dt) {
        pos.px = pos.x;
        pos.pz = pos.z;
        let dx = 0;
        let dz = 0;
        if (input.x !== 0 || input.z !== 0) {
            target = null;
            const fx = -Math.sin(cam.yaw);
            const fz = -Math.cos(cam.yaw);
            dx = fx * input.z + Math.cos(cam.yaw) * input.x;
            dz = fz * input.z - Math.sin(cam.yaw) * input.x;
            const len = Math.hypot(dx, dz) || 1;
            dx /= len;
            dz /= len;
        } else if (target) {
            const tx = target.x - pos.x;
            const tz = target.z - pos.z;
            const d = Math.hypot(tx, tz);
            if (d < 0.2) { target = null; } else { dx = tx / d; dz = tz / d; }
        }
        if (dx === 0 && dz === 0) return;
        const step = WORLD.heroSpeed * dt;
        const nx = pos.x + dx * step;
        const nz = pos.z + dz * step;
        if (isWater(nx, nz)) { target = null; return; } // в воду пока не идём
        pos.x = nx;
        pos.z = nz;
    }

    return {
        scene,
        camera,
        hero,
        chunks,
        get treeCount() { return chunks.treeCount; },
        get heroPos() { return { x: pos.x, z: pos.z }; },
        get cameraYaw() { return cam.yaw; },
        groundY,
        isWater,
        teleport,
        setTarget(x, z) { target = x === null ? null : { x, z }; },
        setMoveInput(x, z) { input.x = x; input.z = z; },
        rotateCamera(dYaw) { cam.yaw += dYaw; },
        update(dt) {
            clock += dt;
            stepHero(dt);
            chunks.setCenter(pos.x, pos.z);
        },
        render(renderer, alpha) {
            chunks.pump();
            placeCamera(alpha);
            water.material.color.setHex(WATER_COLOR).offsetHSL(0, 0, Math.sin(clock * 0.8) * 0.015);
            renderer.render(scene, camera);
        },
        resize(width, height) {
            const aspect = width / Math.max(1, height);
            camera.aspect = aspect;
            // в портрете горизонтальный обзор узкий: шире угол и дальше камера, чтобы кадр не был «в упор»
            const portrait = Math.max(0, 1 - aspect);
            camera.fov = 60 + portrait * 24;
            cam.dist = 11 + portrait * 7;
            camera.updateProjectionMatrix();
        },
        /** Точка земли под лучом из камеры через экранную точку (ndc -1..1). Марш шагом 1 м и уточнение делением пополам. */
        pickGround(ndcX, ndcY) {
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
        },
        /** Пресет качества: дальность, туман, плотность леса, рельеф, тени. */
        applyQuality(next) {
            preset = next;
            scene.fog.far = next.drawDistance;
            scene.fog.near = Math.max(10, next.drawDistance * 0.15);
            camera.far = next.drawDistance * 1.25;
            camera.updateProjectionMatrix();
            sun.castShadow = next.shadows === 'real';
            blob.visible = next.shadows === 'blob';
            const size = next.drawChunks * 32 * 2 + 160;
            water.scale.set(size, 1, size);
            chunks.applyPreset(next);
            chunks.setCenter(pos.x, pos.z);
            // материалы пересоберутся под новое состояние теней
            [chunks.material, body.material].forEach((m) => { m.needsUpdate = true; });
        },
        dispose() {
            chunks.dispose();
            scene.traverse((obj) => {
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material && obj.material !== chunks.material) obj.material.dispose();
            });
        },
    };
}
