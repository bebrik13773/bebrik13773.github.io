import * as THREE from '../three.js';

// Пруд и плотина у поляны (ДЛ-10): простые процедурные объекты без текстур. Состояния и уровни плотины придут с ДЛ-29.
const POND_COLOR = 0x3d8fb8;
const LOG_COLOR = 0x8a5a2b;
const LOG_END_COLOR = 0xc79a62;

/**
 * Строит пруд и плотину по записи сервера { x, z, r, deg, dam: { x, z, level } }.
 * groundY(x, z) даёт высоту земли. Возвращает { group, dispose }.
 */
export function createPondAndDam({ pond, groundY }) {
    const group = new THREE.Group();
    group.name = 'pond-and-dam';

    // Вода: плоский диск на самой высокой точке под прудом, чтобы на склоне не уходил под землю.
    let top = groundY(pond.x, pond.z);
    for (let i = 0; i < 8; i += 1) {
        const a = (i / 8) * Math.PI * 2;
        top = Math.max(top, groundY(pond.x + Math.cos(a) * pond.r * 0.9, pond.z + Math.sin(a) * pond.r * 0.9));
    }
    const waterMat = new THREE.MeshBasicMaterial({ color: POND_COLOR, transparent: true, opacity: 0.82, depthWrite: false, polygonOffset: true, polygonOffsetFactor: -2, polygonOffsetUnits: -2 });
    const water = new THREE.Mesh(new THREE.CircleGeometry(pond.r, 28).rotateX(-Math.PI / 2), waterMat);
    water.position.set(pond.x, top + 0.06, pond.z);
    water.renderOrder = 2;
    group.add(water);

    // Плотина: три ряда брёвен вдоль берега, касательно к краю пруда.
    const dam = pond.dam || { x: pond.x + pond.r, z: pond.z };
    const logMat = new THREE.MeshLambertMaterial({ color: LOG_COLOR });
    const logGeo = new THREE.CylinderGeometry(0.28, 0.28, 3.2, 8).rotateZ(Math.PI / 2);
    const rows = 3;
    const perRow = 5;
    const yaw = -((pond.deg || 0) * Math.PI) / 180 + Math.PI / 2; // вдоль берега
    const base = groundY(dam.x, dam.z);
    const logs = new THREE.InstancedMesh(logGeo, logMat, rows * perRow);
    const m = new THREE.Matrix4();
    const q = new THREE.Quaternion();
    const e = new THREE.Euler(0, yaw, 0);
    q.setFromEuler(e);
    let n = 0;
    for (let row = 0; row < rows; row += 1) {
        for (let i = 0; i < perRow; i += 1) {
            const off = (i - (perRow - 1) / 2) * 0.62;
            // Поперечное смещение: ряды складываются в стенку вдоль берега.
            const nx = Math.cos(yaw) * off;
            const nz = -Math.sin(yaw) * off;
            m.compose(new THREE.Vector3(dam.x + nx, base + 0.3 + row * 0.5, dam.z + nz), q, new THREE.Vector3(1, 1, 1));
            logs.setMatrixAt(n, m);
            n += 1;
        }
    }
    logs.instanceMatrix.needsUpdate = true;
    group.add(logs);

    // Колья по краям.
    const postGeo = new THREE.CylinderGeometry(0.16, 0.2, 2.0, 6);
    const postMat = new THREE.MeshLambertMaterial({ color: LOG_END_COLOR });
    for (const s of [-1, 1]) {
        const post = new THREE.Mesh(postGeo, postMat);
        post.position.set(dam.x + Math.cos(yaw) * 1.7 * s, base + 0.9, dam.z - Math.sin(yaw) * 1.7 * s);
        group.add(post);
    }

    return {
        group,
        dispose() {
            group.removeFromParent();
            waterMat.dispose(); logMat.dispose(); postMat.dispose(); logGeo.dispose(); postGeo.dispose();
            water.geometry.dispose(); logs.dispose();
        },
    };
}

// ── Ящик-тайник и подвода скупщика на поляне (ДЛ-14): простые процедурные модели без текстур ──
const WOOD_COLOR = 0xa8743a;
const WOOD_DARK = 0x6e4a22;
const IRON_COLOR = 0x4a4a52;
const SACK_COLOR = 0xd9c58f;
const CLOTH_COLOR = 0xb8442f;
const FUR_COLOR = 0x8a5a3a;

/**
 * Строит ящик и подводу скупщика. crate и buyer: { x, z }. groundY(x, z) даёт высоту земли.
 * Возвращает { group, dispose } (геометрии и материалы освобождаются вместе с группой).
 */
export function createVillageStalls({ crate, buyer, groundY }) {
    const group = new THREE.Group();
    group.name = 'village-stalls';
    const geos = [];
    const mats = [];
    const mat = (color) => { const m = new THREE.MeshLambertMaterial({ color }); mats.push(m); return m; };
    const mesh = (geo, material, x, y, z, parent = group) => { geos.push(geo); const m = new THREE.Mesh(geo, material); m.position.set(x, y, z); parent.add(m); return m; };
    const wood = mat(WOOD_COLOR);
    const dark = mat(WOOD_DARK);
    const iron = mat(IRON_COLOR);
    const sack = mat(SACK_COLOR);
    const cloth = mat(CLOTH_COLOR);
    const fur = mat(FUR_COLOR);

    // Ящик: короб из досок, крышка чуть шире, две железные полосы и замок.
    if (crate) {
        const g = new THREE.Group();
        g.position.set(crate.x, groundY(crate.x, crate.z), crate.z);
        mesh(new THREE.BoxGeometry(1.0, 0.7, 0.7), wood, 0, 0.35, 0, g);
        mesh(new THREE.BoxGeometry(1.08, 0.12, 0.78), dark, 0, 0.76, 0, g);
        for (const dx of [-0.32, 0.32]) mesh(new THREE.BoxGeometry(0.08, 0.72, 0.74), iron, dx, 0.36, 0, g);
        mesh(new THREE.BoxGeometry(0.14, 0.16, 0.06), iron, 0, 0.62, 0.38, g);
        g.rotation.y = 0.35;
        group.add(g);
    }

    // Подвода: короб на двух колёсах, мешки с зерном, оглобли, навес-тент и сам скупщик (бобёр-торговец в красной шапке).
    if (buyer) {
        const g = new THREE.Group();
        g.position.set(buyer.x, groundY(buyer.x, buyer.z), buyer.z);
        mesh(new THREE.BoxGeometry(1.7, 0.5, 1.0), wood, 0, 0.62, 0, g);
        for (const dz of [-0.62, 0.62]) {
            const wheel = mesh(new THREE.CylinderGeometry(0.42, 0.42, 0.1, 14).rotateX(Math.PI / 2), dark, -0.1, 0.42, dz, g);
            wheel.name = 'wheel';
            mesh(new THREE.CylinderGeometry(0.1, 0.1, 0.14, 8).rotateX(Math.PI / 2), iron, -0.1, 0.42, dz, g);
        }
        for (const dz of [-0.35, 0.35]) mesh(new THREE.CylinderGeometry(0.05, 0.05, 1.6, 6).rotateZ(Math.PI / 2), dark, 1.5, 0.5, dz, g);
        for (const [x, z] of [[-0.45, -0.2], [0.05, 0.2], [0.5, -0.15]]) mesh(new THREE.SphereGeometry(0.3, 9, 7).scale(1, 0.85, 1), sack, x, 1.0, z, g);
        // Тент на двух стойках.
        for (const dz of [-0.46, 0.46]) mesh(new THREE.CylinderGeometry(0.04, 0.04, 1.3, 6), dark, -0.78, 1.15, dz, g);
        mesh(new THREE.BoxGeometry(1.1, 0.06, 1.15), cloth, -0.78, 1.82, 0, g);
        // Скупщик: тело-конус, голова, шапка, хвост.
        const man = new THREE.Group();
        man.position.set(1.0, 0, -1.0);
        mesh(new THREE.CylinderGeometry(0.22, 0.34, 0.8, 10), fur, 0, 0.4, 0, man);
        mesh(new THREE.SphereGeometry(0.24, 10, 8), fur, 0, 1.0, 0, man);
        mesh(new THREE.CylinderGeometry(0.16, 0.26, 0.2, 10), cloth, 0, 1.24, 0, man);
        mesh(new THREE.SphereGeometry(0.06, 6, 5), dark, 0, 1.0, 0.23, man);
        mesh(new THREE.BoxGeometry(0.3, 0.06, 0.5), dark, 0, 0.12, -0.38, man);
        man.rotation.y = -0.9;
        g.add(man);
        g.rotation.y = 0.5;
        group.add(g);
    }

    return {
        group,
        dispose() {
            group.removeFromParent();
            geos.forEach((geo) => geo.dispose());
            mats.forEach((m) => m.dispose());
        },
    };
}
