// Выпавшие и брошенные предметы на земле (ДЛ-11, ДЛ-13): брёвна и находки лежат у пня, подбор тапом или автоматически.
// Одна общая геометрия бревна, по мешу на предмет (их десятки, не тысячи); потолок MAX_DROPS, лишние старые убираются.
// Срок жизни приходит с сервера (ttl, секунды): просроченные исчезают и на экране.
import * as THREE from '../three.js';

const MAX_DROPS = 200;
const DEFAULT_TTL_MS = 900000; // как drop_ttl_s в config.php
const COLORS = { log: 0x8a5a2b, ore: 0x6b7280, coin: 0xf5c542, seed: 0x5aa84a };

export function createDrops({ scene, groundY, onAdd = () => {}, onRemove = () => {}, now = () => Date.now() }) {
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

    function stackScale(qty) { return 1 + 0.12 * Math.min(4, Math.max(0, qty - 1)); }

    function make(d, opts) {
        const key = d.item === 'log' ? 'log' : d.item === 'ore' ? 'ore' : String(d.item).startsWith('seed') ? 'seed' : 'ore';
        const mesh = new THREE.Mesh(d.item === 'log' ? logGeo : stoneGeo, mat(key));
        const rot = (Math.abs(Math.sin(d.id * 12.9898)) * 6.283) % 6.283; // стабильный поворот по id
        mesh.position.set(d.x, groundY(d.x, d.z) + (d.item === 'log' ? 0.16 : 0.2), d.z);
        mesh.rotation.y = rot;
        mesh.scale.setScalar(stackScale(d.qty));
        mesh.userData.drop = { ...d };
        mesh.userData.exp = now() + (Number.isFinite(d.ttl) ? d.ttl * 1000 : DEFAULT_TTL_MS);
        mesh.userData.noAuto = Boolean(opts && opts.noAuto); // брошенное или возвращённое не подбирается само, пока бобёр рядом
        return mesh;
    }

    function drop(id) {
        const m = meshes.get(id);
        if (!m) return false;
        group.remove(m);
        meshes.delete(id);
        onRemove(id, m.userData.drop);
        return true;
    }

    return {
        group,
        /** Добавить предметы. opts.noAuto: не подбирать автоматически (брошенные самим игроком). */
        add(list, opts) {
            for (const d of list || []) {
                if (!d) continue;
                const had = meshes.get(d.id);
                if (had) { // уже лежит: обновляем число (стопка уменьшилась после частичного подбора)
                    had.userData.drop.qty = d.qty;
                    had.scale.setScalar(stackScale(d.qty));
                    continue;
                }
                const m = make(d, opts);
                meshes.set(d.id, m);
                group.add(m);
                onAdd(m.userData.drop, m);
            }
            while (meshes.size > MAX_DROPS) drop(meshes.keys().next().value);
        },
        remove(id) { return drop(id); },
        get(id) { const m = meshes.get(id); return m ? m.userData.drop : null; },
        /** Метка «не подбирать само» (true) или снятие метки. */
        setNoAuto(id, flag) { const m = meshes.get(id); if (m) m.userData.noAuto = Boolean(flag); },
        isNoAuto(id) { const m = meshes.get(id); return Boolean(m && m.userData.noAuto); },
        /** Убирает просроченные, возвращает их id. */
        expire() {
            const t = now();
            const gone = [];
            for (const [id, m] of meshes) if (m.userData.exp <= t) gone.push(id);
            gone.forEach(drop);
            return gone;
        },
        clear() { for (const id of [...meshes.keys()]) drop(id); },
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
