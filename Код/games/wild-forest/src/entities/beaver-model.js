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
    legL: [0.15, 0.4, 0],
    legR: [-0.15, 0.4, 0],
    tail1: [0, 0.38, -0.3],
    tail2: [0, 0.2, -0.5],
    axe: [-0.34, 0.5, 0.05],
    logs: [0, 0.66, 0.36],
    food: [0, 1.0, 0.4],
});

export const PARENT = Object.freeze({
    root: null, hips: 'root', torso: 'hips', head: 'torso', armL: 'torso', armR: 'torso',
    legL: 'hips', legR: 'hips', tail1: 'hips', tail2: 'tail1', axe: 'armR', logs: 'torso', food: 'head',
});

/** Точка подвеса фонаря (мировые координаты покоя) и размер светящегося шара по уровню. */
export const LAMP_POINT = Object.freeze([0.31, 0.405, 0.10]);
export const lampGlowRadius = (level) => 0.034 + 0.006 * level;

// Сколько сегментов у примитивов. high для ближнего плана и среднего-высокого качества, low для слабых телефонов и дальних бобров.
const SEG = {
    high: { big: [13, 9], mid: [10, 7], small: [7, 5], tiny: [6, 4], cyl: 8, cone: 6 },
    low: { big: [8, 6], mid: [6, 4], small: [5, 4], tiny: [4, 3], cyl: 5, cone: 4 },
};

/** Бюджеты из плана (раздел ДЛ-07): модель без контура. */
export const TRIANGLE_BUDGET = Object.freeze({ high: 3500, low: 1500 });

const tmpColor = new THREE.Color();
const tmpMatrix = new THREE.Matrix4();
const tmpQuat = new THREE.Quaternion();
const tmpEuler = new THREE.Euler();
const tmpPos = new THREE.Vector3();
const tmpScale = new THREE.Vector3();

/** Накопитель частей: превращает примитивы Three.js в плоские массивы вершин со скинингом. */
function createBuilder(palette, detail) {
    const pos = []; const nrm = []; const col = []; const bone = []; const index = [];
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
        for (let i = 0; i < count; i += 1) bone.push(bi);
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
                index: vcount > 65535 ? new Uint32Array(index) : new Uint16Array(index),
                vertices: vcount,
                triangles: index.length / 3,
            };
        },
    };
    return api;
}

// ---------- части тела ----------

function buildBody(b, hi) {
    const S = b.seg;
    // туловище и брюшко
    b.ell(S.big, 0, 0.62, 0, 0.36, 0.40, 0.32, 'fur', 'torso');
    b.ell(S.mid, 0, 0.60, 0.12, 0.27, 0.32, 0.22, 'belly', 'torso');
    // голова
    b.ell(S.big, 0, 1.06, 0.06, 0.315, 0.276, 0.30, 'fur', 'head');
    b.ell(S.mid, 0, 0.99, 0.30, 0.17, 0.12, 0.14, 'belly', 'head');                 // мордочка
    b.ell(S.tiny, 0, 1.035, 0.435, 0.055, 0.042, 0.04, 'nose', 'head');              // нос
    b.box(0.05, 0.085, 0.03, 0.032, 0.915, 0.405, 'tooth', 'head');                  // резцы
    b.box(0.05, 0.085, 0.03, -0.032, 0.915, 0.405, 'tooth', 'head');
    for (const side of [1, -1]) {
        b.ell(S.small, side * 0.13, 1.12, 0.305, 0.075, 0.075, 0.07, 'eyeWhite', 'head');   // глаза
        b.ell(S.tiny, side * 0.13, 1.115, 0.362, 0.042, 0.045, 0.03, 'pupil', 'head');
        b.ell(S.small, side * 0.2, 1.31, 0.0, 0.08, 0.08, 0.05, 'fur', 'head');               // уши
        b.ell(S.tiny, side * 0.2, 1.31, 0.035, 0.05, 0.05, 0.03, 'earInner', 'head');
        if (hi) b.ell(S.small, side * 0.18, 0.98, 0.2, 0.1, 0.09, 0.09, 'fur', 'head');       // щёки
    }
    // лапы и ноги
    for (const side of [1, -1]) {
        const arm = side > 0 ? 'armL' : 'armR';
        const leg = side > 0 ? 'legL' : 'legR';
        b.cyl(0.075, 0.32, side * 0.34, 0.67, 0.04, 'fur', arm, { r2: 0.07 });
        b.ell(S.small, side * 0.34, 0.50, 0.05, 0.095, 0.095, 0.095, 'furDark', arm);          // кисть
        b.cyl(0.10, 0.30, side * 0.15, 0.25, 0, 'fur', leg, { r2: 0.095 });
        b.ell(S.small, side * 0.15, 0.055, 0.10, 0.12, 0.055, 0.20, 'feet', leg);              // большая ступня
    }
    // хвост: основание и плоская лопата
    b.ell(S.small, 0, 0.26, -0.42, 0.10, 0.10, 0.15, 'furDark', 'tail1');
    b.ell(S.mid, 0, 0.12, -0.76, 0.20, 0.035, 0.30, 'tail', 'tail2');
    if (hi) {
        // узор «чешуек» на лопате: шашечки из плоских шестигранников
        const rows = [-0.53, -0.63, -0.73, -0.83, -0.93];
        rows.forEach((z, r) => {
            const xs = r % 2 === 0 ? [-0.08, 0.08] : [0, -0.15, 0.15];
            xs.forEach((x) => {
                if ((x / 0.19) ** 2 + ((z + 0.76) / 0.29) ** 2 > 0.85) return;
                b.cyl(0.042, 0.012, x, 0.158, z, 'tailPattern', 'tail2', { seg: 5 });
            });
        });
        // пучки жёсткого меха: на макушке и плечах
        [[0, 1.34, 0.12, 0.0], [0.09, 1.33, 0.03, -0.35], [-0.09, 1.33, 0.03, 0.35]].forEach(([x, y, z, tilt]) => {
            b.cone(0.045, 0.14, x, y + 0.04, z, 'furDark', 'head', { rz: tilt });
        });
        [[0.27, 0.99, 0.0, -0.9], [-0.27, 0.99, 0.0, 0.9]].forEach(([x, y, z, tilt]) => {
            b.cone(0.05, 0.13, x, y, z, 'furDark', 'torso', { rz: tilt });
        });
    }
}

// ---------- предметы в руках (скрываются сжатием кости) ----------

function buildHandProps(b, gear, hi) {
    // топор: рукоять вниз от кисти, лезвие смотрит вперёд (+Z). Размер и металл зависят от уровня топора.
    const lvl = gear.axe;
    const metal = [0x8e9aa3, 0xa9b3bb, 0x9fc2d8, 0x7fd0c0, 0xe6b422, 0xc47aff][lvl - 1];
    const grow = 1 + 0.08 * (lvl - 1);
    b.cyl(0.026, 0.53, -0.34, 0.265, 0.05, 'wood', 'axe', { seg: hi ? 6 : 5 });
    b.box(0.05, 0.12 * grow, 0.17 * grow, -0.34, 0.14, 0.08 + 0.02 * grow, metal, 'axe', {
        pre(geo) { // трапеция: лезвие шире обуха
            const p = geo.attributes.position;
            for (let i = 0; i < p.count; i += 1) if (p.getZ(i) > 0) p.setY(i, p.getY(i) * 1.6);
            geo.computeVertexNormals();
        },
    });
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
    b.ell(S.mid, 0, 0.66, -0.36, 0.22 * s, 0.24 * s, 0.12 * s, 'pack', 'torso');           // мешок
    b.box(0.30 * s, 0.05, 0.25 * s, 0, 0.62 + 0.24 * s, -0.37, 'packDark', 'torso');         // клапан
    for (const side of [1, -1]) b.box(0.05, 0.30, 0.02, side * 0.17, 0.70, 0.285, 'packDark', 'torso', { rx: 0.1 }); // лямки
    if (level >= 3) for (const side of [1, -1]) b.ell(S.small, side * 0.25 * s, 0.58, -0.34, 0.07 * s, 0.09 * s, 0.07 * s, 'packDark', 'torso'); // боковые карманы
    if (level >= 5) {
        b.cyl(0.06, 0.46 * s, 0, 0.64 + 0.28 * s, -0.36, 'packRoll', 'torso', { rz: Math.PI / 2, capColor: 'packDark' }); // скатка
        if (hi) for (const x of [-0.12, 0.12]) b.cyl(0.063, 0.03, x * s, 0.64 + 0.28 * s, -0.36, 'packDark', 'torso', { rz: Math.PI / 2 });
    }
    if (level >= 7) b.ell(S.small, 0, 0.42, -0.42, 0.14 * s, 0.1 * s, 0.07 * s, 'pack', 'torso'); // второй мешок
    if (level >= 8) b.ell(S.tiny, 0, 0.60 + 0.24 * s, -0.5, 0.04, 0.04, 0.025, 'gold', 'torso'); // золотая пряжка
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
            .replace('#include <common>', '#include <common>\nuniform float uOutline;')
            .replace('#include <skinning_vertex>', '#include <skinning_vertex>\n\ttransformed += objectNormal * uOutline;');
    };
    mat.customProgramCacheKey = () => 'wfBeaverOutline';
    return mat;
}
