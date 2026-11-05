import * as THREE from '../three.js';

// Вид постройки (ДЛ-16): процедурная мультяшная модель из простых форм без текстур, четыре видимые стадии (фундамент, стены, крыша, готово),
// «призрак» для выбора места (зелёный или красный), хлопок пыли и подпрыгивание при каждом тапе стройки.
// ДЛ-17: чёрный контур (перевёрнутая оболочка), три видимых уровня (пристройки и детали), леса вокруг постройки, пока идёт улучшение.
// Материалы общие на все постройки (по цвету), геометрии свои и освобождаются в dispose.

const COLORS = {
    wood: 0xa8743a, woodDark: 0x6e4a22, woodLight: 0xc79a62, stone: 0x8d8f94, stoneDark: 0x5c5e63, roofRed: 0xb8442f, roofBrown: 0x7a4a2a,
    straw: 0xd9c58f, door: 0x5a3a1e, glass: 0x9fd4e8, soil: 0x5b3d22, leaf: 0x4f9d3a, iron: 0x4a4a52, glow: 0xff8a2a, cloth1: 0xc0392b, cloth2: 0xf1e6c8,
};
const matCache = new Map();
function mat(color) {
    let m = matCache.get(color);
    if (!m) { m = new THREE.MeshLambertMaterial({ color }); matCache.set(color, m); }
    return m;
}
const OUTLINE_MAT = new THREE.MeshBasicMaterial({ color: 0x15110d, side: THREE.BackSide });
const OUTLINE_T = 0.05; // толщина контура, м
const PUFF_MAT = new THREE.MeshBasicMaterial({ color: 0xe9dfc9, transparent: true, opacity: 0.8, depthWrite: false });

/** Описание частей по типу: { base, walls, roof, extra } — функции, добавляющие меши в группу. u = радиус габарита, h = высота. */
function parts(type, u, h, add) {
    const box = (w, hh, d, x, y, z, color, g) => add(new THREE.BoxGeometry(w, hh, d), color, x, y, z, g);
    const cyl = (rt, rb, hh, x, y, z, color, g, seg = 10) => add(new THREE.CylinderGeometry(rt, rb, hh, seg), color, x, y, z, g);
    const roofGable = (w, d, hh, color, g, y) => { const m = add(new THREE.ConeGeometry(Math.hypot(w, d) * 0.5, hh, 4), color, 0, y, 0, g); m.rotation.y = Math.PI / 4; m.scale.set(w / Math.hypot(w, d) * 1.414, 1, d / Math.hypot(w, d) * 1.414); return m; };
    const w = u * 1.6; const d = u * 1.35;
    const out = { base: [], walls: [], roof: [], extra: [], lv2: [], lv3: [] };
    const G = (k) => ({ g: k });
    const slab = (color) => out.base.push((g) => { box(u * 2.1, 0.25, u * 2.1, 0, 0.12, 0, color, g); });
    if (type === 'house' || type === 'workshop') {
        const wall = type === 'house' ? COLORS.woodLight : COLORS.wood;
        const rf = type === 'house' ? COLORS.roofRed : COLORS.roofBrown;
        slab(COLORS.stone);
        out.walls.push((g) => { box(w, h * 0.5, d, 0, 0.25 + h * 0.25, 0, wall, g); });
        out.roof.push((g) => { roofGable(w * 1.15, d * 1.2, h * 0.45, rf, g, 0.25 + h * 0.5 + h * 0.225); });
        out.extra.push((g) => {
            box(0.5, 0.9, 0.08, 0, 0.7, d / 2 + 0.02, COLORS.door, g);
            box(0.45, 0.4, 0.08, w * 0.3, 1.2, d / 2 + 0.02, COLORS.glass, g);
            cyl(0.2, 0.24, h * 0.55, -w * 0.3, 0.25 + h * 0.6, -d * 0.2, COLORS.stoneDark, g, 8);
            if (type === 'workshop') { box(1.0, 0.1, 0.6, w * 0.65, 0.7, 0, COLORS.woodDark, g); box(0.3, 0.25, 0.3, w * 0.65, 0.9, 0, COLORS.iron, g); }
        });
    } else if (type === 'warehouse') {
        slab(COLORS.stone);
        out.walls.push((g) => { box(u * 2.0, h * 0.5, u * 1.5, 0, 0.25 + h * 0.25, 0, COLORS.wood, g); });
        out.roof.push((g) => { roofGable(u * 2.2, u * 1.7, h * 0.4, COLORS.roofBrown, g, 0.25 + h * 0.5 + h * 0.2); });
        out.extra.push((g) => {
            box(1.3, 1.3, 0.08, 0, 0.9, u * 0.75 + 0.02, COLORS.door, g);
            box(0.8, 0.6, 0.8, u * 0.95, 0.4, u * 0.9, COLORS.woodDark, g);
            box(0.6, 0.5, 0.6, u * 0.95, 0.95, u * 0.9, COLORS.wood, g);
        });
    } else if (type === 'garden') {
        out.base.push((g) => { box(u * 2.0, 0.2, u * 2.0, 0, 0.1, 0, COLORS.soil, g); });
        out.walls.push((g) => { for (const s of [-1, 1]) { box(u * 2.0, 0.5, 0.1, 0, 0.4, s * u, COLORS.wood, g); box(0.1, 0.5, u * 2.0, s * u, 0.4, 0, COLORS.wood, g); } });
        out.roof.push((g) => { for (const x of [-0.9, 0, 0.9]) for (const z of [-0.9, 0.9]) cyl(0.08, 0.08, 0.5, x, 0.45, z, COLORS.leaf, g, 5); });
        out.extra.push((g) => { for (const x of [-0.9, 0, 0.9]) for (const z of [-0.9, 0.9]) add(new THREE.SphereGeometry(0.28, 7, 6), COLORS.leaf, x, 0.8, z, g); });
    } else if (type === 'sawmill') {
        slab(COLORS.woodDark);
        out.walls.push((g) => { for (const sx of [-1, 1]) for (const sz of [-1, 1]) cyl(0.14, 0.14, h * 0.7, sx * u * 0.8, 0.25 + h * 0.35, sz * u * 0.6, COLORS.wood, g, 6); });
        out.roof.push((g) => { const m = add(new THREE.BoxGeometry(u * 2.0, 0.12, u * 1.6), COLORS.roofBrown, 0, 0.25 + h * 0.72, 0, g); m.rotation.z = 0.12; });
        out.extra.push((g) => { box(1.6, 0.5, 0.6, 0, 0.5, 0, COLORS.woodDark, g); cyl(0.4, 0.4, 0.05, 0, 0.95, 0, COLORS.iron, g, 14).rotation.x = Math.PI / 2; for (let i = 0; i < 3; i += 1) cyl(0.18, 0.18, 1.1, u * 0.9, 0.3 + i * 0.35, u * 0.5, COLORS.woodLight, g, 8).rotation.z = Math.PI / 2; });
    } else if (type === 'market') {
        slab(COLORS.wood);
        out.walls.push((g) => { for (const sx of [-1, 1]) cyl(0.1, 0.1, h * 0.75, sx * u * 0.85, 0.25 + h * 0.37, u * 0.5, COLORS.woodDark, g, 6); box(u * 1.8, 0.6, 0.6, 0, 0.6, -u * 0.3, COLORS.wood, g); });
        out.roof.push((g) => { for (let i = 0; i < 6; i += 1) box(u * 1.8 / 6, 0.08, u * 1.5, -u * 0.9 + (i + 0.5) * (u * 1.8 / 6), 0.25 + h * 0.78 - (i % 2) * 0.01, 0, i % 2 ? COLORS.cloth2 : COLORS.cloth1, g); });
        out.extra.push((g) => { box(0.5, 0.35, 0.5, -0.5, 1.0, -u * 0.3, COLORS.straw, g); box(0.4, 0.3, 0.4, 0.5, 1.0, -u * 0.3, COLORS.woodLight, g); });
    } else if (type === 'quarry') {
        out.base.push((g) => { cyl(u * 1.0, u * 1.1, 0.2, 0, 0.1, 0, COLORS.stoneDark, g, 12); });
        out.walls.push((g) => { for (const [x, z, s] of [[-1, -0.6, 0.9], [0.9, -0.2, 0.7], [0.1, 0.9, 0.8]]) add(new THREE.DodecahedronGeometry(s * 0.7, 0), COLORS.stone, x, 0.5, z, g); });
        out.roof.push((g) => { for (const k of [-1, 1]) cyl(0.07, 0.07, h * 0.9, k * 0.5, 0.2 + h * 0.4, 0.8, COLORS.woodDark, g, 5).rotation.z = k * 0.3; });
        out.extra.push((g) => { box(0.9, 0.1, 0.1, 0, h * 0.85, 0.8, COLORS.woodDark, g); cyl(0.02, 0.02, 0.7, 0.4, h * 0.85 - 0.35, 0.8, COLORS.iron, g, 4); });
    } else if (type === 'smelter' || type === 'forge') {
        slab(COLORS.stoneDark);
        const forge = type === 'forge';
        out.walls.push((g) => { if (forge) box(u * 1.7, h * 0.5, u * 1.4, 0, 0.25 + h * 0.25, 0, COLORS.stone, g); else cyl(u * 0.6, u * 0.85, h * 0.6, 0, 0.25 + h * 0.3, 0, COLORS.stone, g, 10); });
        out.roof.push((g) => { if (forge) roofGable(u * 1.9, u * 1.6, h * 0.35, COLORS.roofBrown, g, 0.25 + h * 0.5 + h * 0.17); else cyl(u * 0.3, u * 0.55, h * 0.4, 0, 0.25 + h * 0.6 + h * 0.2, 0, COLORS.stoneDark, g, 10); });
        out.extra.push((g) => { cyl(0.25, 0.3, h * 0.45, forge ? u * 0.5 : 0, 0.25 + h * (forge ? 0.7 : 0.85), forge ? -u * 0.3 : 0, COLORS.stoneDark, g, 8); box(0.7, 0.55, 0.1, 0, 0.6, forge ? u * 0.7 + 0.02 : u * 0.65, COLORS.glow, g); if (forge) { box(0.6, 0.3, 0.4, u * 0.9, 0.5, 0.2, COLORS.iron, g); } });
    } else {
        slab(COLORS.stone);
        out.walls.push((g) => { box(w, h * 0.5, d, 0, 0.25 + h * 0.25, 0, COLORS.wood, g); });
        out.roof.push((g) => { roofGable(w * 1.15, d * 1.2, h * 0.4, COLORS.roofBrown, g, 0.25 + h * 0.5 + h * 0.2); });
    }
    // Уровни: 2 и 3 добавляют пристройки и детали (виден рост постройки).
    const lv = (n, fn) => out[`lv${n}`].push(fn);
    if (type === 'warehouse') {
        lv(2, (g) => { box(0.9, 0.8, 0.9, -u * 0.95, 0.4, u * 0.9, COLORS.woodDark, g); box(0.7, 0.6, 0.7, -u * 0.95, 1.1, u * 0.9, COLORS.wood, g); box(0.5, 0.5, 0.5, -u * 0.5, 0.35, u * 0.95, COLORS.woodLight, g); });
        lv(3, (g) => { box(u * 0.8, h * 0.34, u * 1.1, u * 1.3, 0.25 + h * 0.17, 0, COLORS.wood, g); box(u * 0.95, 0.12, u * 1.25, u * 1.3, 0.25 + h * 0.36, 0, COLORS.roofBrown, g); box(0.6, 0.8, 0.08, u * 1.3, 0.65, u * 0.56, COLORS.door, g); });
    } else if (type === 'house') {
        lv(2, (g) => { box(w * 0.55, 0.12, 0.9, 0, 0.3, d / 2 + 0.5, COLORS.woodDark, g); box(0.5, 0.3, 0.2, w * 0.3, 0.95, d / 2 + 0.15, COLORS.leaf, g); box(0.5, 0.18, 0.2, w * 0.3, 0.78, d / 2 + 0.15, COLORS.woodLight, g); });
        lv(3, (g) => { box(w * 0.5, h * 0.36, d * 0.8, -w * 0.75, 0.25 + h * 0.18, 0, COLORS.woodLight, g); box(w * 0.58, 0.12, d * 0.95, -w * 0.75, 0.25 + h * 0.38, 0, COLORS.roofRed, g); box(0.4, 0.35, 0.08, -w * 0.75, 0.9, d * 0.4 + 0.02, COLORS.glass, g); });
    } else if (type === 'market') {
        lv(2, (g) => { box(0.6, 0.5, 0.6, u * 0.55, 0.5, -u * 0.3, COLORS.woodLight, g); box(0.5, 0.4, 0.5, u * 0.55, 0.95, -u * 0.3, COLORS.straw, g); cyl(0.05, 0.05, h * 0.9, -u * 0.95, 0.25 + h * 0.45, -u * 0.6, COLORS.woodDark, g, 5); box(0.5, 0.35, 0.04, -u * 0.95 + 0.3, 0.25 + h * 0.8, -u * 0.6, COLORS.cloth1, g); });
        lv(3, (g) => { box(u * 0.9, 0.55, 0.5, u * 1.45, 0.55, 0.2, COLORS.wood, g); for (let i = 0; i < 3; i += 1) box(u * 0.3, 0.08, 0.9, u * 1.15 + i * u * 0.3, 0.25 + h * 0.62, 0.2, i % 2 ? COLORS.cloth2 : COLORS.cloth1, g); for (const k of [-1, 1]) cyl(0.06, 0.06, h * 0.6, u * 1.45 + k * u * 0.42, 0.25 + h * 0.3, 0.55, COLORS.woodDark, g, 5); });
    } else if (type === 'workshop') {
        lv(2, (g) => { box(0.5, 0.35, 0.3, -w * 0.7, 0.45, d * 0.2, COLORS.iron, g); box(0.3, 0.2, 0.3, -w * 0.7, 0.72, d * 0.2, COLORS.iron, g); cyl(0.28, 0.28, 0.12, -w * 0.7, 0.5, -d * 0.2, COLORS.stone, g, 10).rotation.z = Math.PI / 2; });
        lv(3, (g) => { box(1.3, 0.08, 0.1, 0, 0.25 + h * 0.4, d / 2 + 0.06, COLORS.woodDark, g); for (const k of [-0.45, 0, 0.45]) box(0.08, 0.35, 0.05, k, 0.25 + h * 0.4 - 0.2, d / 2 + 0.1, COLORS.iron, g); cyl(0.3, 0.34, h * 0.25, -w * 0.3, 0.25 + h * 0.84, -d * 0.2, COLORS.stoneDark, g, 8); box(0.45, 0.45, 0.08, -w * 0.3, 1.2, d / 2 + 0.02, COLORS.glow, g); });
    }
    void G;
    return out;
}

/** type, r (радиус габарита), h (высота). Возвращает вид: { group, setStage, setGhost, tap, update, dispose }. */
export function createBuildingView({ type, r = 2.8, h = 3.5 }) {
    const group = new THREE.Group();
    group.name = `building-${type}`;
    const geos = [];
    let outlineOn = true;
    const add = (geo, color, x, y, z, g) => {
        geos.push(geo);
        const m = new THREE.Mesh(geo, mat(color)); m.position.set(x, y, z); m.userData.color = color;
        geo.computeBoundingBox();
        const sz = geo.boundingBox.getSize(new THREE.Vector3());
        const o = new THREE.Mesh(geo, OUTLINE_MAT);
        o.scale.set(1 + (2 * OUTLINE_T) / Math.max(sz.x, 0.2), 1 + (2 * OUTLINE_T) / Math.max(sz.y, 0.2), 1 + (2 * OUTLINE_T) / Math.max(sz.z, 0.2));
        o.userData.outline = true;
        m.add(o);
        g.add(m);
        return m;
    };
    const layers = { base: new THREE.Group(), walls: new THREE.Group(), roof: new THREE.Group(), extra: new THREE.Group(), lv2: new THREE.Group(), lv3: new THREE.Group() };
    const spec = parts(type, r, h, add);
    for (const k of Object.keys(layers)) { group.add(layers[k]); for (const fn of spec[k]) fn(layers[k]); }

    let stage = 'ready';
    let level = 1;
    let upgrading = false;
    let scaffold = null;
    let ghost = null;            // null | 'ok' | 'bad'
    let blueprint = false;       // чертёж без единого тапа: прозрачный
    let squash = 0;
    let puffs = [];
    const ghostMats = { ok: new THREE.MeshBasicMaterial({ color: 0x4ad16f, transparent: true, opacity: 0.55, depthWrite: false }), bad: new THREE.MeshBasicMaterial({ color: 0xe0503c, transparent: true, opacity: 0.55, depthWrite: false }) };
    const bpMat = new THREE.MeshBasicMaterial({ color: 0x6fa8dc, transparent: true, opacity: 0.45, depthWrite: false });

    // Леса: четыре столба по углам и две перекладины, пока идёт улучшение уровня.
    function makeScaffold() {
        const sg = new THREE.Group();
        const poleGeo = new THREE.CylinderGeometry(0.06, 0.06, h * 0.95, 5);
        const barGeo = new THREE.BoxGeometry(r * 2.3, 0.07, 0.07);
        geos.push(poleGeo, barGeo);
        const pm = mat(COLORS.woodLight);
        for (const sx of [-1, 1]) for (const sz of [-1, 1]) { const m = new THREE.Mesh(poleGeo, pm); m.position.set(sx * r * 1.15, h * 0.475, sz * r * 1.15); sg.add(m); }
        for (const sz of [-1, 1]) for (const y of [0.35, 0.7]) { const m = new THREE.Mesh(barGeo, pm); m.position.set(0, h * y, sz * r * 1.15); sg.add(m); }
        group.add(sg);
        return sg;
    }

    function apply() {
        const order = ['foundation', 'walls', 'roof', 'ready'];
        const i = order.indexOf(stage);
        layers.base.visible = true;
        layers.walls.visible = i >= 1;
        layers.roof.visible = i >= 2;
        layers.extra.visible = i >= 3;
        layers.lv2.visible = i >= 3 && level >= 2;
        layers.lv3.visible = i >= 3 && level >= 3;
        if (upgrading && !scaffold) scaffold = makeScaffold();
        if (scaffold) scaffold.visible = upgrading && !ghost;
        group.traverse((o) => {
            if (!o.isMesh || o.userData.puff) return;
            if (o.userData.outline) { o.visible = outlineOn && !ghost && !blueprint; return; }
            o.material = ghost ? ghostMats[ghost] : blueprint ? bpMat : mat(o.userData.color);
        });
    }
    apply();

    return {
        group, r, h,
        get stage() { return stage; },
        /** Стадия по прогрессу: foundation, walls, roof, ready; blueprint=true рисует прозрачный чертёж. */
        setStage(next, isBlueprint = false) { if (next === stage && isBlueprint === blueprint) return; stage = next; blueprint = isBlueprint; apply(); },
        get level() { return level; },
        /** Уровень постройки: 1…3 (на уровнях 2 и 3 видны пристройки). */
        setLevel(n) { const v = Math.max(1, Math.min(3, Math.trunc(Number(n)) || 1)); if (v === level) return; level = v; apply(); },
        /** Идёт улучшение: вокруг стоят леса. */
        setUpgrading(on) { if (Boolean(on) === upgrading) return; upgrading = Boolean(on); apply(); },
        /** Чёрный контур вкл/выкл (на слабом качестве выключаем). */
        setOutline(on) { if (Boolean(on) === outlineOn) return; outlineOn = Boolean(on); apply(); },
        /** Призрак при выборе места: 'ok' (зелёный), 'bad' (красный) или null. Показывает готовую модель целиком. */
        setGhost(kind) { ghost = kind; if (kind) { stage = 'ready'; blueprint = false; } apply(); },
        /** Тап стройки: подпрыгивание и облачко пыли. */
        tap() {
            squash = 1;
            if (puffs.length > 8) return;
            const m = new THREE.Mesh(new THREE.SphereGeometry(0.22, 6, 5), PUFF_MAT);
            m.userData.puff = true;
            const a = Math.random() * Math.PI * 2;
            m.position.set(Math.cos(a) * r * 0.9, 0.3, Math.sin(a) * r * 0.9);
            group.add(m);
            puffs.push({ m, t: 0 });
        },
        update(dt) {
            if (squash > 0) { squash = Math.max(0, squash - dt * 5); const k = Math.sin(squash * Math.PI) * 0.04; group.scale.set(1 + k, 1 - k, 1 + k); }
            if (puffs.length) {
                for (const p of puffs) { p.t += dt; p.m.position.y += dt * 1.1; p.m.scale.setScalar(1 + p.t * 2.2); }
                puffs = puffs.filter((p) => { if (p.t < 0.6) return true; group.remove(p.m); p.m.geometry.dispose(); return false; });
            }
        },
        dispose() {
            group.removeFromParent();
            geos.forEach((g) => g.dispose());
            for (const p of puffs) p.m.geometry.dispose();
            ghostMats.ok.dispose(); ghostMats.bad.dispose(); bpMat.dispose();
        },
    };
}
