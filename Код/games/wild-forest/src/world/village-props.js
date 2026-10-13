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
