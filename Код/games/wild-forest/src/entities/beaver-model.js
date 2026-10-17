import * as THREE from '../three.js';
import { BONES, B } from './beaver-anim.js';
import { resolveSkin, resolveGear } from './skins.js';

// Процедурная модель бобра (ДЛ-07). Всё тело, снаряжение и аксессуары собираются в ОДНУ геометрию со скинингом:
// каждая вершина целиком привязана к одной кости (жёсткие части, как у игрушки). Поэтому бобёр рисуется
// двумя вызовами отрисовки (тело и контур), а не десятками. Цвета лежат в вершинах, текстур нет.
// Предметы в руках (топор, брёвна, рыба) всегда есть в геометрии, а скрываются сжатием своей кости до нуля.
//
// Единицы: метры. Бобёр стоит на y = 0 и смотрит в +Z. Высота с ушами около 1.35 м.

/** Положение суставов в покое (мировые координаты модели). */
export const REST = Object.freeze({
    root: [0, 0, 0],
    hips: [0, 0.42, 0],
    torso: [0, 0.5, 0],
    head: [0, 0.98, 0.02],
    armL: [0.33, 0.82, 0.02],
    armR: [-0.33, 0.82, 0.02],
    foreL: [0.355, 0.645, 0.05],   // локоть
    foreR: [-0.355, 0.645, 0.05],
    legL: [0.15, 0.4, 0],
    legR: [-0.15, 0.4, 0],
    footL: [0.15, 0.13, 0],        // щиколотка
    footR: [-0.15, 0.13, 0],
    tail1: [0, 0.38, -0.3],
    tail2: [0, 0.2, -0.5],
    axe: [-0.36, 0.5, 0.09],       // кисть правой лапы: топор лежит в ней рукоятью вперёд
    logs: [0, 0.66, 0.36],
    food: [0, 1.0, 0.4],
});

export const PARENT = Object.freeze({
    root: null, hips: 'root', torso: 'hips', head: 'torso', armL: 'torso', armR: 'torso',
    foreL: 'armL', foreR: 'armR', legL: 'hips', legR: 'hips', footL: 'legL', footR: 'legR',
    tail1: 'hips', tail2: 'tail1', axe: 'foreR', logs: 'torso', food: 'head',
});

/** Точка подвеса фонаря (мировые координаты покоя) и размер светящегося шара по уровню. */
export const LAMP_POINT = Object.freeze([0.31, 0.405, 0.10]);
export const lampGlowRadius = (level) => 0.034 + 0.006 * level;

// Сколько сегментов у примитивов. high для ближнего плана и среднего-высокого качества, low для слабых телефонов и дальних бобров.
const SEG = {
    high: { big: [13, 9], mid: [10, 7], small: [7, 5], tiny: [6, 4], cyl: 8, cone: 6, strap: 14, strapRad: 5 },
    low: { big: [8, 6], mid: [6, 4], small: [5, 4], tiny: [4, 3], cyl: 5, cone: 4, strap: 8, strapRad: 4 },
};

/** Бюджеты из плана (раздел ДЛ-07): модель без контура. */
export const TRIANGLE_BUDGET = Object.freeze({ high: 5200, low: 2300 });

const tmpColor = new THREE.Color();
const tmpMatrix = new THREE.Matrix4();
const tmpQuat = new THREE.Quaternion();
const tmpEuler = new THREE.Euler();
const tmpPos = new THREE.Vector3();
const tmpScale = new THREE.Vector3();

/** Накопитель частей: превращает примитивы Three.js в плоские массивы вершин со скинингом. */
function createBuilder(palette, detail) {
    const pos = []; const nrm = []; const col = []; const bone = []; const index = []; const ow = [];
    const seg = SEG[detail];
    const hex = (c) => (typeof c === 'string' ? palette[c] : c);

    /**
     * geo: индексная геометрия Three.js. opts: bone, color (имя из палитры или число), x y z, sx sy sz, rx ry rz,
     * capColor (цвет торцов цилиндра), pre (функция правки геометрии до поворота и сдвига).
     */
    function add(geo, o) {
        if (o.pre) o.pre(geo);
        const base = pos.length / 3;
        const n0 = geo.attributes.normal.array;
        const count = geo.attributes.position.count;
        const colors = new Float32Array(count * 3);
        tmpColor.set(hex(o.color));
        const r0 = tmpColor.r; const g0 = tmpColor.g; const b0 = tmpColor.b;
        let cr = r0; let cg = g0; let cb = b0;
        if (o.capColor !== undefined) { tmpColor.set(hex(o.capColor)); cr = tmpColor.r; cg = tmpColor.g; cb = tmpColor.b; }
        for (let i = 0; i < count; i += 1) {
            const cap = o.capColor !== undefined && Math.abs(n0[i * 3 + 1]) > 0.9;
            colors[i * 3] = cap ? cr : r0; colors[i * 3 + 1] = cap ? cg : g0; colors[i * 3 + 2] = cap ? cb : b0;
        }
        tmpEuler.set(o.rx || 0, o.ry || 0, o.rz || 0, 'XYZ');
        tmpQuat.setFromEuler(tmpEuler);
        tmpPos.set(o.x || 0, o.y || 0, o.z || 0);
        tmpScale.set(o.sx === undefined ? 1 : o.sx, o.sy === undefined ? 1 : o.sy, o.sz === undefined ? 1 : o.sz);
        tmpMatrix.compose(tmpPos, tmpQuat, tmpScale);
        geo.applyMatrix4(tmpMatrix); // позиции и нормали (с нормальной матрицей, нормали остаются единичными)
        const p = geo.attributes.position.array;
        const n = geo.attributes.normal.array;
        for (let i = 0; i < p.length; i += 1) { pos.push(p[i]); nrm.push(n[i]); }
        for (let i = 0; i < colors.length; i += 1) col.push(colors[i]);
        const bi = B[o.bone];
        const w = o.ow === undefined ? 1 : o.ow; // множитель контура: 0 = у детали нет своей обводки
        for (let i = 0; i < count; i += 1) { bone.push(bi); ow.push(w); }
        const idx = geo.index.array;
        for (let i = 0; i < idx.length; i += 1) index.push(idx[i] + base);
        geo.dispose();
    }

    const api = {
        seg,
        add,
        /** Эллипсоид: size = [ширина, высота] сетки, центр (x,y,z), радиусы (rx,ry,rz). */
        ell(size, x, y, z, rx, ry, rz, color, boneName, extra = {}) {
            add(new THREE.SphereGeometry(1, size[0], size[1]), { x, y, z, sx: rx, sy: ry, sz: rz, color, bone: boneName, ...extra });
        },
        /** Цилиндр вдоль Y: радиус, высота, центр. */
        cyl(r, h, x, y, z, color, boneName, extra = {}) {
            add(new THREE.CylinderGeometry(r, extra.r2 === undefined ? r : extra.r2, h, extra.seg || seg.cyl, 1), { x, y, z, color, bone: boneName, ...extra });
        },
        cone(r, h, x, y, z, color, boneName, extra = {}) {
            add(new THREE.ConeGeometry(r, h, extra.seg || seg.cone, 1), { x, y, z, color, bone: boneName, ...extra });
        },
        box(w, h, d, x, y, z, color, boneName, extra = {}) {
            add(new THREE.BoxGeometry(w, h, d), { x, y, z, color, bone: boneName, ...extra });
        },
        /** Труба по точкам (лямки): гладкая кривая через points (THREE.Vector3), радиус r. */
        tube(points, r, color, boneName, extra = {}) {
            const curve = new THREE.CatmullRomCurve3(points, false, 'catmullrom', 0.5);
            add(new THREE.TubeGeometry(curve, extra.steps || seg.strap, r, seg.strapRad, false), { color, bone: boneName, ...extra });
        },
        torus(r, tube, rs, ts, x, y, z, color, boneName, extra = {}) {
            add(new THREE.TorusGeometry(r, tube, rs, ts), { x, y, z, color, bone: boneName, ...extra });
        },
        finish() {
            const vcount = pos.length / 3;
            const skinIndex = new Uint16Array(vcount * 4);
            const skinWeight = new Float32Array(vcount * 4);
            for (let i = 0; i < vcount; i += 1) { skinIndex[i * 4] = bone[i]; skinWeight[i * 4] = 1; }
            return {
                positions: new Float32Array(pos),
                normals: new Float32Array(nrm),
                colors: new Float32Array(col),
                skinIndex,
                skinWeight,
                outlineW: new Float32Array(ow),
                index: vcount > 65535 ? new Uint32Array(index) : new Uint16Array(index),
                vertices: vcount,
                triangles: index.length / 3,
            };
        },
    };
    return api;
}

// ---------- части тела ----------

const TORSO_C = [0, 0.62, 0];
const TORSO_R = [0.36, 0.40, 0.32];

/** Точка на поверхности туловища (эллипсоид) в плоскости x = const; phi: 0 вверху, π/2 спереди, −π/2 сзади; off: вынос по нормали. */
function torsoSection(x, phi, off) {
    const k = Math.sqrt(Math.max(1e-4, 1 - (x / TORSO_R[0]) ** 2));
    const y = TORSO_C[1] + TORSO_R[1] * k * Math.cos(phi);
    const z = TORSO_R[2] * k * Math.sin(phi);
    return surfaceOffset(x, y, z, off);
}

function surfaceOffset(x, y, z, off) {
    const nx = x / (TORSO_R[0] * TORSO_R[0]);
    const ny = (y - TORSO_C[1]) / (TORSO_R[1] * TORSO_R[1]);
    const nz = z / (TORSO_R[2] * TORSO_R[2]);
    const len = Math.hypot(nx, ny, nz) || 1;
    return new THREE.Vector3(x + (nx / len) * off, y + (ny / len) * off, z + (nz / len) * off);
}

/** Точка на груди спереди для данных x и y (z берётся с поверхности туловища). */
function chestPoint(x, y, off) {
    const t = 1 - (x / TORSO_R[0]) ** 2 - ((y - TORSO_C[1]) / TORSO_R[1]) ** 2;
    return surfaceOffset(x, y, TORSO_R[2] * Math.sqrt(Math.max(1e-4, t)), off);
}

function buildBody(b, hi) {
    const S = b.seg;
    // туловище и брюшко
    b.ell(S.big, 0, 0.62, 0, 0.36, 0.40, 0.32, 'fur', 'torso');
    b.ell(S.mid, 0, 0.60, 0.12, 0.27, 0.32, 0.22, 'belly', 'torso', { ow: 0 });
    // голова
    b.ell(S.big, 0, 1.06, 0.06, 0.315, 0.276, 0.30, 'fur', 'head');
    b.ell(S.mid, 0, 0.99, 0.30, 0.17, 0.12, 0.14, 'belly', 'head', { ow: 0.5 });     // мордочка (контур тоньше)
    b.ell(S.tiny, 0, 1.035, 0.435, 0.055, 0.042, 0.04, 'nose', 'head', { ow: 0 });              // нос
    b.box(0.05, 0.085, 0.03, 0.032, 0.915, 0.405, 'tooth', 'head', { ow: 0 });                  // резцы
    b.box(0.05, 0.085, 0.03, -0.032, 0.915, 0.405, 'tooth', 'head', { ow: 0 });
    for (const side of [1, -1]) {
        b.ell(S.small, side * 0.13, 1.12, 0.305, 0.075, 0.075, 0.07, 'eyeWhite', 'head', { ow: 0 });   // глаза
        b.ell(S.tiny, side * 0.13, 1.115, 0.362, 0.042, 0.045, 0.03, 'pupil', 'head', { ow: 0 });
        if (hi) b.ell(S.tiny, side * 0.12, 1.135, 0.385, 0.014, 0.014, 0.01, 'eyeWhite', 'head', { ow: 0 }); // блик
        // ушки: маленькие, круглые, сидят низко по бокам головы и наполовину в меху (не мышиные)
        b.ell(S.small, side * 0.245, 1.225, -0.02, 0.05, 0.062, 0.042, 'fur', 'head', { rz: side * -0.35 });
        if (hi) b.ell(S.tiny, side * 0.262, 1.222, 0.005, 0.026, 0.036, 0.02, 'furDark', 'head', { ow: 0, rz: side * -0.35 });
        if (hi) b.ell(S.small, side * 0.18, 0.98, 0.2, 0.1, 0.09, 0.09, 'fur', 'head', { ow: 0 });       // щёки
    }
    for (const side of [1, -1]) {
        const arm = side > 0 ? 'armL' : 'armR';
        const fore = side > 0 ? 'foreL' : 'foreR';
        const leg = side > 0 ? 'legL' : 'legR';
        const foot = side > 0 ? 'footL' : 'footR';
        // рука: плечо, предплечье, локоть, лапа с пальцами и коготками
        b.ell(S.small, side * 0.31, 0.84, 0.02, 0.115, 0.11, 0.11, 'fur', arm);
        b.cyl(0.088, 0.20, side * 0.335, 0.745, 0.035, 'fur', arm, { r2: 0.074 });
        b.ell(S.small, side * 0.355, 0.645, 0.05, 0.078, 0.078, 0.078, 'fur', arm);
        b.cyl(0.074, 0.15, side * 0.36, 0.565, 0.07, 'fur', fore, { r2: 0.066 });
        b.ell(S.small, side * 0.36, 0.49, 0.095, 0.092, 0.08, 0.105, 'furDark', fore);
        [-1, 0, 1].forEach((i) => {
            b.ell(S.tiny, side * 0.36 + i * 0.04, 0.47, 0.185, 0.026, 0.026, 0.038, 'furDark', fore);
            if (hi) b.cone(0.011, 0.04, side * 0.36 + i * 0.04, 0.465, 0.232, 'nose', fore, { rx: Math.PI / 2, seg: 3, ow: 0 });
        });
        // нога: бедро, щиколотка, широкая стопа с перепонкой и пальцами
        b.cyl(0.105, 0.28, side * 0.15, 0.27, 0, 'fur', leg, { r2: 0.09 });
        b.ell(S.small, side * 0.15, 0.14, 0.0, 0.095, 0.09, 0.095, 'fur', foot);
        b.ell(S.small, side * 0.15, 0.055, 0.11, 0.115, 0.05, 0.19, 'feet', foot);
        [-1, 0, 1].forEach((i) => b.ell(S.tiny, side * 0.15 + i * 0.075, 0.045, 0.285, 0.04, 0.036, 0.05, 'feet', foot));
        if (hi) [-1, 0, 1].forEach((i) => b.cone(0.014, 0.05, side * 0.15 + i * 0.075, 0.045, 0.34, 'nose', foot, { rx: Math.PI / 2, seg: 3, ow: 0 }));
    }
    // хвост: основание уходит в тело, потом переходное звено и плоская лопата с тёмной каймой (без щелей)
    b.ell(S.small, 0, 0.30, -0.33, 0.12, 0.12, 0.18, 'furDark', 'tail1');
    b.ell(S.small, 0, 0.19, -0.52, 0.10, 0.085, 0.15, 'furDark', 'tail2');
    b.ell(S.big, 0, 0.122, -0.80, 0.215, 0.030, 0.325, 'tailPattern', 'tail2');
    b.ell(S.big, 0, 0.135, -0.80, 0.20, 0.036, 0.30, 'tail', 'tail2');
    if (hi) {
        // три продольных желобка вместо «шашечек»: тонкие и длинные
        [-0.09, 0, 0.09].forEach((x) => b.ell(S.tiny, x, 0.164 - Math.abs(x) * 0.06, -0.80, 0.008, 0.01, 0.23, 'tailPattern', 'tail2', { ow: 0 }));
    }
}

// ---------- предметы в руках (скрываются сжатием кости) ----------

function buildHandProps(b, gear, hi) {
    // топор лежит в правой кисти: рукоять вперёд (+Z), головка на дальнем конце, лезвие смотрит вниз.
    // Размер и металл зависят от уровня топора. Кость axe стоит в кисти, поэтому топор вращается вокруг хвата.
    const lvl = gear.axe;
    const metal = [0x8e9aa3, 0xa9b3bb, 0x9fc2d8, 0x7fd0c0, 0xe6b422, 0xc47aff][lvl - 1];
    const grow = 1 + 0.08 * (lvl - 1);
    const hx = -0.36; const hy = 0.5; const hz = 0.09;
    b.cyl(0.026, 0.66, hx, hy, hz + 0.20, 'wood', 'axe', { rx: Math.PI / 2, seg: hi ? 6 : 5 });
    b.ell(b.seg.tiny, hx, hy, hz - 0.13, 0.034, 0.034, 0.03, 'wood', 'axe');
    const headZ = hz + 0.46;
    b.box(0.05, 0.19 * grow, 0.15 * grow, hx, hy - 0.035, headZ, metal, 'axe', {
        pre(geo) { // клин: лезвие шире и тоньше, обух толще
            const p = geo.attributes.position;
            for (let i = 0; i < p.count; i += 1) {
                if (p.getY(i) < 0) { p.setX(i, p.getX(i) * 0.35); p.setZ(i, p.getZ(i) * 1.45); }
            }
            geo.computeVertexNormals();
        },
    });
    if (hi) b.box(0.012, 0.02, 0.22 * grow, hx, hy - 0.128 * grow, headZ, 0xe9eef2, 'axe', { ow: 0 }); // блик на кромке
    // охапка брёвен: три полена лежат поперёк, торцы светлее
    [[0.62, 0.36], [0.62, 0.51], [0.745, 0.435]].forEach(([y, z]) => {
        b.cyl(0.075, 0.70, 0, y, z, 'log', 'logs', { rz: Math.PI / 2, capColor: 'logCap' });
    });
    // рыба для еды: лежит поперёк рта
    b.ell(b.seg.small, 0, 0.95, 0.47, 0.17, 0.06, 0.06, 'fish', 'food');
    b.cone(0.06, 0.1, 0.21, 0.95, 0.47, 'fish', 'food', { rz: -Math.PI / 2, seg: 4 });
    b.ell(b.seg.tiny, -0.12, 0.975, 0.515, 0.014, 0.014, 0.014, 'pupil', 'food');
}

// ---------- рюкзак по уровню (0 нет, 1…8) ----------

function buildPack(b, level, hi) {
    if (level <= 0) return;
    const S = b.seg;
    const s = 1 + 0.07 * (level - 1);
    const pz = -0.345;
    b.ell(S.mid, 0, 0.64, pz, 0.22 * s, 0.25 * s, 0.12 * s, 'pack', 'torso');                       // мешок
    b.ell(S.small, 0, 0.64 + 0.17 * s, pz - 0.005, 0.205 * s, 0.10 * s, 0.135 * s, 'packDark', 'torso'); // клапан, закрывает верх
    b.ell(S.small, 0, 0.49, pz - 0.115, 0.15 * s, 0.10 * s, 0.05 * s, 'pack', 'torso');             // передний карман
    b.box(0.05, 0.055, 0.02, 0, 0.545, pz - 0.15, 'gold', 'torso', { ow: 0 });                       // пряжка кармана
    // лямки идут по поверхности туловища (с выносом 1.8 см), поэтому не уходят в тело и не висят в воздухе
    for (const side of [1, -1]) {
        const x = side * 0.17;
        const pts = [-1.55, -0.8, 0, 0.7, 1.25, 1.6].map((phi) => torsoSection(x, phi, 0.018));
        b.tube(pts, 0.021, 'packDark', 'torso');
    }
    const chest = [-0.17, -0.06, 0.06, 0.17].map((x) => chestPoint(x, 0.72, 0.026));
    b.tube(chest, 0.014, 'packDark', 'torso');                                                        // нагрудный ремень
    b.box(0.045, 0.05, 0.016, 0, 0.72, chestPoint(0, 0.72, 0.03).z, 'gold', 'torso', { ow: 0 });     // его пряжка
    if (level >= 3) for (const side of [1, -1]) b.ell(S.small, side * 0.25 * s, 0.58, pz, 0.07 * s, 0.09 * s, 0.07 * s, 'packDark', 'torso'); // боковые карманы
    if (level >= 5) {
        b.cyl(0.06, 0.46 * s, 0, 0.64 + 0.30 * s, pz, 'packRoll', 'torso', { rz: Math.PI / 2, capColor: 'packDark' }); // скатка
        if (hi) for (const x of [-0.12, 0.12]) b.cyl(0.063, 0.03, x * s, 0.64 + 0.30 * s, pz, 'packDark', 'torso', { rz: Math.PI / 2 });
    }
    if (level >= 7) b.ell(S.small, 0, 0.36, pz - 0.05, 0.14 * s, 0.09 * s, 0.07 * s, 'pack', 'torso'); // второй мешок
    if (level >= 8) b.ell(S.tiny, 0, 0.64 + 0.2 * s, pz - 0.14, 0.04, 0.04, 0.025, 'gold', 'torso'); // золотая пряжка
}

// ---------- фонарь (0 нет, 1…4) ----------

function buildLamp(b, level) {
    if (level <= 0) return;
    const [x, y, z] = LAMP_POINT;
    b.cyl(0.05, 0.012, x, y + 0.05, z, 'metal', 'torso', { seg: 6 });  // крышка
    b.cyl(0.05, 0.012, x, y - 0.05, z, 'metal', 'torso', { seg: 6 });  // дно
    for (const [dx, dz] of [[0.04, 0], [-0.04, 0], [0, 0.04], [0, -0.04]]) b.box(0.01, 0.10, 0.01, x + dx, y, z + dz, 'metal', 'torso');
    b.box(0.012, 0.10, 0.012, x, y + 0.11, z, 'metal', 'torso');       // подвес к поясу
}

// ---------- аксессуары скина: слоты hat, scarf, glasses ----------

export const ACCESSORY_BUILDERS = Object.freeze({
    hat: {
        beanie(b, a) {
            b.ell(b.seg.small, 0, 1.28, 0.03, 0.325, 0.25, 0.32, a.color, 'head');
            b.ell(b.seg.tiny, 0, 1.52, 0.03, 0.07, 0.07, 0.07, a.color2, 'head');
        },
        cap(b, a) {
            b.ell(b.seg.small, 0, 1.27, 0.03, 0.325, 0.19, 0.32, a.color, 'head');
            b.cyl(0.2, 0.025, 0, 1.2, 0.34, a.color2, 'head', { seg: 8, sx: 1, sz: 0.8 });
        },
        tophat(b, a) {
            b.cyl(0.19, 0.30, 0, 1.46, 0.02, a.color, 'head', { seg: 8 });
            b.cyl(0.30, 0.03, 0, 1.31, 0.02, a.color, 'head', { seg: 10 });
            b.cyl(0.195, 0.05, 0, 1.38, 0.02, a.color2, 'head', { seg: 8 });
        },
    },
    scarf: {
        wool(b, a) {
            b.torus(0.27, 0.07, 5, 12, 0, 0.92, 0.0, a.color, 'torso', { rx: Math.PI / 2 });
            b.box(0.12, 0.30, 0.04, 0.16, 0.76, 0.30, a.color, 'torso', { rx: 0.1 });
        },
    },
    glasses: {
        round(b, a) {
            for (const side of [1, -1]) b.torus(0.085, 0.012, 4, 10, side * 0.13, 1.12, 0.37, a.color, 'head');
            b.box(0.07, 0.014, 0.014, 0, 1.125, 0.38, a.color, 'head');
            for (const side of [1, -1]) b.box(0.014, 0.014, 0.2, side * 0.225, 1.12, 0.27, a.color, 'head');
        },
    },
});

function buildAccessories(b, skin, hi) {
    for (const slot of ['hat', 'scarf', 'glasses']) {
        const a = skin.accessories[slot];
        if (!a) continue;
        if (!hi && slot === 'glasses') continue; // на слабом качестве мелочь не рисуем
        ACCESSORY_BUILDERS[slot][a.kind](b, a);
    }
}

/** Собирает данные модели. detail: 'high' | 'low'. Возвращает массивы вершин и статистику. */
export function buildBeaverData({ detail = 'high', skin, gear } = {}) {
    const d = detail === 'low' ? 'low' : 'high';
    const s = resolveSkin(skin);
    const g = resolveGear(gear);
    const b = createBuilder(s.palette, d);
    const hi = d === 'high';
    buildBody(b, hi);
    buildHandProps(b, g, hi);
    buildPack(b, g.pack, hi);
    buildLamp(b, g.lamp);
    buildAccessories(b, s, hi);
    const data = b.finish();
    data.detail = d;
    data.skin = s;
    data.gear = g;
    return data;
}

/**
 * Нормали для контура: вершины в одной точке пространства получают одну общую нормаль (среднюю),
 * иначе при раздувании оболочки на стыках примитивов (торцы цилиндров, швы сфер) образуются щели.
 */
export function weldNormals(positions, normals) {
    const out = new Float32Array(normals.length);
    const sums = new Map();
    const key = (i) => `${Math.round(positions[i * 3] * 1000)},${Math.round(positions[i * 3 + 1] * 1000)},${Math.round(positions[i * 3 + 2] * 1000)}`;
    const n = positions.length / 3;
    for (let i = 0; i < n; i += 1) {
        const k = key(i);
        let s = sums.get(k);
        if (!s) { s = [0, 0, 0]; sums.set(k, s); }
        s[0] += normals[i * 3]; s[1] += normals[i * 3 + 1]; s[2] += normals[i * 3 + 2];
    }
    for (let i = 0; i < n; i += 1) {
        const s = sums.get(key(i));
        const len = Math.hypot(s[0], s[1], s[2]) || 1;
        out[i * 3] = s[0] / len; out[i * 3 + 1] = s[1] / len; out[i * 3 + 2] = s[2] / len;
    }
    return out;
}

/** Геометрии Three.js: тело и оболочка контура (общие позиции, индексы и кости, свои нормали). */
export function createBeaverGeometries(data) {
    const position = new THREE.BufferAttribute(data.positions, 3);
    const skinIndex = new THREE.BufferAttribute(data.skinIndex, 4);
    const skinWeight = new THREE.BufferAttribute(data.skinWeight, 4);
    const index = new THREE.BufferAttribute(data.index, 1);

    const body = new THREE.BufferGeometry();
    body.setAttribute('position', position);
    body.setAttribute('normal', new THREE.BufferAttribute(data.normals, 3));
    body.setAttribute('color', new THREE.BufferAttribute(data.colors, 3));
    body.setAttribute('skinIndex', skinIndex);
    body.setAttribute('skinWeight', skinWeight);
    body.setIndex(index);
    body.computeBoundingSphere();

    const hull = new THREE.BufferGeometry();
    hull.setAttribute('position', position);
    hull.setAttribute('normal', new THREE.BufferAttribute(weldNormals(data.positions, data.normals), 3));
    hull.setAttribute('skinIndex', skinIndex);
    hull.setAttribute('skinWeight', skinWeight);
    hull.setAttribute('outlineW', new THREE.BufferAttribute(data.outlineW, 1));
    hull.setIndex(index);
    hull.computeBoundingSphere();
    return { body, hull };
}

/** Градиент для мультяшного затенения: три чётких тона. Одна текстура на все материалы бобров. */
let gradient = null;
export function getToonGradient() {
    if (!gradient) {
        gradient = new THREE.DataTexture(new Uint8Array([120, 190, 255]), 3, 1, THREE.RedFormat);
        gradient.minFilter = THREE.NearestFilter;
        gradient.magFilter = THREE.NearestFilter;
        gradient.generateMipmaps = false;
        gradient.needsUpdate = true;
    }
    return gradient;
}

/**
 * Материал контура: чёрная оболочка с обратной стороной граней, раздутая вдоль нормалей на uOutline метров
 * (после скининга, поэтому следует за анимацией). Кость, сжатая до нуля (скрытый предмет), даёт нулевую нормаль и пропадает.
 */
export function createOutlineMaterial(uniform) {
    const mat = new THREE.MeshBasicMaterial({ color: 0x000000, side: THREE.BackSide });
    mat.onBeforeCompile = (shader) => {
        shader.uniforms.uOutline = uniform;
        shader.vertexShader = shader.vertexShader
            .replace('#include <common>', '#include <common>\nuniform float uOutline;\nattribute float outlineW;')
            .replace('#include <skinning_vertex>', '#include <skinning_vertex>\n\ttransformed += objectNormal * (uOutline * outlineW);');
    };
    mat.customProgramCacheKey = () => 'wfBeaverOutline';
    return mat;
}
