// Выпавшие предметы на земле (ДЛ-11): брёвна и находки лежат у пня. Подбор придёт в ДЛ-13, пока только показ.
// Одна общая геометрия бревна, по мешу на предмет (их десятки, не тысячи); потолок MAX_DROPS, лишние старые убираются.
import * as THREE from '../three.js';

const MAX_DROPS = 200;
const COLORS = { log: 0x8a5a2b, ore: 0x6b7280, coin: 0xf5c542, seed: 0x5aa84a };

export function createDrops({ scene, groundY }) {
    const group = new THREE.Group();
    group.name = 'drops';
    scene.add(group);
    const logGeo = new THREE.CylinderGeometry(0.16, 0.16, 0.9, 8).rotateZ(Math.PI / 2);
    const stoneGeo = new THREE.DodecahedronGeometry(0.24, 0);
    const mats = new Map();
    const meshes = new Map(); // id → mesh

    function mat(key) {
        const color = COLORS[key] ?? COLORS.log;
        if (!mats.has(color)) mats.set(color, new THREE.MeshLambertMaterial({ color }));
        return mats.get(color);
    }

    function make(d) {
        const key = d.item === 'log' ? 'log' : d.item === 'ore' ? 'ore' : String(d.item).startsWith('seed') ? 'seed' : 'ore';
        const mesh = new THREE.Mesh(d.item === 'log' ? logGeo : stoneGeo, mat(key));
        const rot = (Math.abs(Math.sin(d.id * 12.9898)) * 6.283) % 6.283; // стабильный поворот по id
        mesh.position.set(d.x, groundY(d.x, d.z) + (d.item === 'log' ? 0.16 : 0.2), d.z);
        mesh.rotation.y = rot;
        mesh.userData.drop = d;
        return mesh;
    }

    return {
        group,
        add(list) {
            for (const d of list || []) {
                if (!d || meshes.has(d.id)) continue;
                const m = make(d);
                meshes.set(d.id, m);
                group.add(m);
            }
            while (meshes.size > MAX_DROPS) {
                const [oldId, oldMesh] = meshes.entries().next().value;
                group.remove(oldMesh);
                meshes.delete(oldId);
            }
        },
        remove(id) {
            const m = meshes.get(id);
            if (!m) return false;
            group.remove(m);
            meshes.delete(id);
            return true;
        },
        clear() { for (const m of meshes.values()) group.remove(m); meshes.clear(); },
        get count() { return meshes.size; },
        list() { return [...meshes.values()].map((m) => m.userData.drop); },
        dispose() {
            this.clear();
            scene.remove(group);
            logGeo.dispose();
            stoneGeo.dispose();
            mats.forEach((m) => m.dispose());
        },
    };
}
