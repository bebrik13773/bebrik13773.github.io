import * as THREE from '../three.js';

// Вид саженцев в саду (ДЛ-19): простое мультяшное деревце без текстур, размер растёт со стадией (росток, молодое, среднее, взрослое),
// засохшее серо-коричневое, политое с влажным тёмным пятном у корней. Материалы общие, геометрии общие на все саженцы.
const CONIFER = new Set(['spruce', 'pine', 'ancient_cedar']);
const LEAF = { spruce: 0x2f6b3a, pine: 0x3c7a42, birch: 0x9acb55, aspen: 0x86b84a, maple: 0xd9762b, oak: 0x4f8f2f, black_alder: 0x3d5f3a, gold_birch: 0xe8c23a, ancient_cedar: 0x1f5a45, iron_oak: 0x56606b };
const TRUNK = { birch: 0xe8e4d8, gold_birch: 0xe8dcc0, iron_oak: 0x2f2f36 };
const mats = new Map();
function mat(color) {
    let m = mats.get(color);
    if (!m) { m = new THREE.MeshLambertMaterial({ color }); mats.set(color, m); }
    return m;
}
const G_TRUNK = new THREE.CylinderGeometry(0.07, 0.11, 1, 6);
const G_CONE = new THREE.ConeGeometry(0.5, 1, 7);
const G_BALL = new THREE.SphereGeometry(0.5, 7, 6);
const G_WET = new THREE.CircleGeometry(0.45, 10);
const WET = new THREE.MeshBasicMaterial({ color: 0x2b1d10, transparent: true, opacity: 0.5, depthWrite: false });

/** Высота взрослого дерева по размеру: среднее 2.6 м, большое 3.4 м. */
export function saplingHeight(frac, big) {
    const full = big ? 3.4 : 2.6;
    return 0.35 + (full - 0.35) * Math.max(0, Math.min(1, frac));
}

/** s: ответ сервера { species, state, frac, watered, big }. Возвращает { group, update(s), dispose() }. */
export function createSaplingView(s) {
    const group = new THREE.Group();
    const trunk = new THREE.Mesh(G_TRUNK, mat(TRUNK[s.species] || 0x7a5230));
    const crown = new THREE.Mesh(CONIFER.has(s.species) ? G_CONE : G_BALL, mat(LEAF[s.species] || 0x4f9d3a));
    const wet = new THREE.Mesh(G_WET, WET);
    wet.rotation.x = -Math.PI / 2;
    wet.position.y = 0.03;
    group.add(wet, trunk, crown);

    function update(next) {
        const dry = next.state === 'dry';
        const h = dry ? Math.max(0.35, saplingHeight(next.frac, false) * 0.7) : saplingHeight(next.frac, next.big);
        const w = 0.55 + 0.9 * Math.min(1, (h - 0.3) / 2.6);
        trunk.scale.set(1 + h * 0.1, h * 0.55, 1 + h * 0.1);
        trunk.position.y = h * 0.275;
        const conifer = CONIFER.has(next.species);
        crown.scale.set(w, conifer ? h * 0.7 : w * 1.05, w);
        crown.position.y = conifer ? h * 0.55 + h * 0.35 : h * 0.55 + w * 0.4;
        crown.material = dry ? mat(0x8a7a5c) : mat(LEAF[next.species] || 0x4f9d3a);
        crown.visible = !dry || h > 0.4;
        wet.visible = Boolean(next.watered) && !dry;
    }
    update(s);

    return {
        group, update,
        dispose() { group.removeFromParent(); },
    };
}
