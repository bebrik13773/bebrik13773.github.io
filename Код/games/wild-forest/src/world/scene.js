import * as THREE from '../three.js';

// Тестовая сцена каркаса (ДЛ-04): земля, «лес» из инстансов и маркер героя.
// Нужна, чтобы смена качества была видна: тени, дальность тумана, плотность деревьев.
// Настоящий мир (чанки, биомы) приходит в ДЛ-05 и ДЛ-06.
const MAX_TREES = 1600;
const FIELD = 360; // сторона квадрата с деревьями, м
const SKY = 0xbfe3f2;

function seededRandom(seed) {
    let s = seed >>> 0;
    return () => {
        s = (Math.imul(s, 1664525) + 1013904223) >>> 0;
        return s / 4294967296;
    };
}

export function createWorldScene() {
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(SKY);
    scene.fog = new THREE.Fog(SKY, 20, 160);

    const camera = new THREE.PerspectiveCamera(60, 1, 0.3, 300);

    const hemi = new THREE.HemisphereLight(0xdff3ff, 0x4f6b3a, 0.9);
    scene.add(hemi);
    const sun = new THREE.DirectionalLight(0xfff1d0, 1.1);
    sun.position.set(40, 70, 25);
    sun.castShadow = false;
    sun.shadow.mapSize.set(1024, 1024);
    const cam = sun.shadow.camera;
    cam.left = -40; cam.right = 40; cam.top = 40; cam.bottom = -40; cam.near = 1; cam.far = 200;
    scene.add(sun);
    scene.add(sun.target);

    // Земля: сетка с цветами вершин (без текстур).
    const groundGeo = new THREE.PlaneGeometry(FIELD * 2, FIELD * 2, 48, 48);
    groundGeo.rotateX(-Math.PI / 2);
    const rnd = seededRandom(7);
    const colors = [];
    const base = new THREE.Color(0x5f8f45);
    const tmp = new THREE.Color();
    for (let i = 0; i < groundGeo.attributes.position.count; i += 1) {
        tmp.copy(base).offsetHSL(0, 0, (rnd() - 0.5) * 0.12);
        colors.push(tmp.r, tmp.g, tmp.b);
    }
    groundGeo.setAttribute('color', new THREE.Float32BufferAttribute(colors, 3));
    const ground = new THREE.Mesh(groundGeo, new THREE.MeshLambertMaterial({ vertexColors: true }));
    ground.receiveShadow = true;
    scene.add(ground);

    // Деревья: ствол и крона двумя InstancedMesh (2 вызова отрисовки на весь лес).
    const trunkGeo = new THREE.CylinderGeometry(0.25, 0.35, 2, 6);
    trunkGeo.translate(0, 1, 0);
    const crownGeo = new THREE.ConeGeometry(1.6, 5, 7);
    crownGeo.translate(0, 4.2, 0);
    const trunks = new THREE.InstancedMesh(trunkGeo, new THREE.MeshLambertMaterial({ color: 0x6b4a2b }), MAX_TREES);
    const crowns = new THREE.InstancedMesh(crownGeo, new THREE.MeshLambertMaterial({ color: 0x2f6b3b }), MAX_TREES);
    const matrix = new THREE.Matrix4();
    const quat = new THREE.Quaternion();
    const pos = new THREE.Vector3();
    const scl = new THREE.Vector3();
    const rt = seededRandom(1234);
    for (let i = 0; i < MAX_TREES; i += 1) {
        let x = (rt() - 0.5) * FIELD;
        let z = (rt() - 0.5) * FIELD;
        if (Math.hypot(x, z) < 6) { x += 12; z += 12; } // поляна в центре
        const s = 0.8 + rt() * 0.9;
        pos.set(x, 0, z);
        scl.set(s, s, s);
        matrix.compose(pos, quat, scl);
        trunks.setMatrixAt(i, matrix);
        crowns.setMatrixAt(i, matrix);
    }
    trunks.castShadow = true;
    crowns.castShadow = true;
    trunks.frustumCulled = false;
    crowns.frustumCulled = false;
    scene.add(trunks, crowns);

    // Маркер героя (заменит 3D-бобёр в ДЛ-07).
    const hero = new THREE.Group();
    const body = new THREE.Mesh(new THREE.CapsuleGeometry(0.4, 0.7, 4, 8), new THREE.MeshLambertMaterial({ color: 0x9a6a3a }));
    body.position.y = 0.75;
    body.castShadow = true;
    hero.add(body);
    scene.add(hero);

    // Простая тень-пятно под героем (среднее качество).
    const blob = new THREE.Mesh(
        new THREE.CircleGeometry(0.7, 16).rotateX(-Math.PI / 2),
        new THREE.MeshBasicMaterial({ color: 0x000000, transparent: true, opacity: 0.35, depthWrite: false }),
    );
    blob.position.y = 0.02;
    hero.add(blob);

    // Состояние демо-камеры: орбита вокруг героя, предыдущий и текущий угол для интерполяции.
    const orbit = { prev: 0, curr: 0, radius: 10, height: 6 };

    function placeCamera(alpha) {
        const angle = orbit.prev + (orbit.curr - orbit.prev) * alpha;
        camera.position.set(Math.cos(angle) * orbit.radius, orbit.height, Math.sin(angle) * orbit.radius);
        camera.lookAt(0, 1, 0);
    }

    return {
        scene,
        camera,
        hero,
        get treeCount() { return trunks.count; },
        update(dt) {
            orbit.prev = orbit.curr;
            orbit.curr += dt * 0.25;
        },
        render(renderer, alpha) {
            placeCamera(alpha);
            renderer.render(scene, camera);
        },
        resize(width, height) {
            camera.aspect = width / Math.max(1, height);
            camera.updateProjectionMatrix();
        },
        // Пресет качества: дальность, плотность деревьев, тени.
        applyQuality(preset) {
            scene.fog.far = preset.drawDistance;
            scene.fog.near = Math.max(10, preset.drawDistance * 0.15);
            camera.far = preset.drawDistance * 1.25;
            camera.updateProjectionMatrix();
            const count = Math.max(1, Math.round(MAX_TREES * preset.treeDensity));
            trunks.count = count;
            crowns.count = count;
            sun.castShadow = preset.shadows === 'real';
            blob.visible = preset.shadows === 'blob';
            trunks.castShadow = preset.shadows === 'real';
            crowns.castShadow = preset.shadows === 'real';
            body.castShadow = preset.shadows === 'real';
            ground.receiveShadow = preset.shadows === 'real';
            // материалы пересоберутся под новое состояние теней
            [ground.material, trunks.material, crowns.material, body.material].forEach((m) => { m.needsUpdate = true; });
        },
        dispose() {
            scene.traverse((obj) => {
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material) obj.material.dispose();
            });
        },
    };
}
