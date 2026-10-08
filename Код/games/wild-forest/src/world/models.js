import * as THREE from '../three.js';

// Процедурные модели мира (ДЛ-06): деревья десяти пород, булыжник, руда, пень.
// Всё без текстур: цвет лежит в вершинах. Шаблон строится один раз и копируется в буфер чанка
// с поворотом и масштабом (см. chunk-build.js). Единицы шаблона: метры, основание на y = 0.
//
// Два уровня подробности: 0 «простая» для дальних чанков и низкого качества, 1 «подробная» для ближних.
// Порядок пород = индекс в species.json (менять нельзя вместе с ним).

const tmpColor = new THREE.Color();

/** Шаблон: плоские массивы позиций, нормалей и цветов (без индексов, грани плоские, как у мультяшных моделей). */
function createTemplate() {
    return { pos: [], nrm: [], col: [], height: 1, radius: 0.3 };
}

/**
 * Добавляет в шаблон часть из геометрии Three.js.
 * opts: x, y, z (положение), sx, sy, sz (масштаб), ry (поворот вокруг Y), color (hex).
 */
function addPart(tpl, geometry, opts) {
    const { x = 0, y = 0, z = 0, sx = 1, sy = 1, sz = 1, ry = 0, color = 0xffffff } = opts;
    const geo = geometry.index ? geometry.toNonIndexed() : geometry.clone();
    geo.rotateY(ry);
    geo.scale(sx, sy, sz);
    geo.translate(x, y, z);
    geo.computeVertexNormals(); // у неиндексированной геометрии нормаль на грань: получается плоская заливка
    tmpColor.set(color); // hex переводится в рабочее (линейное) пространство
    const p = geo.attributes.position.array;
    const n = geo.attributes.normal.array;
    for (let i = 0; i < p.length; i += 1) {
        tpl.pos.push(p[i]);
        tpl.nrm.push(n[i]);
    }
    for (let i = 0; i < p.length / 3; i += 1) tpl.col.push(tmpColor.r, tmpColor.g, tmpColor.b);
    geo.dispose();
    geometry.dispose();
}

function finish(tpl, height, radius) {
    return {
        pos: new Float32Array(tpl.pos),
        nrm: new Float32Array(tpl.nrm),
        col: new Float32Array(tpl.col),
        verts: tpl.pos.length / 3,
        height,
        radius,
    };
}

const trunk = (tpl, h, r0, r1, seg, color) => addPart(tpl, new THREE.CylinderGeometry(r1, r0, h, seg, 1), { y: h / 2, color });
const cone = (tpl, y, h, r, seg, color, ry = 0) => addPart(tpl, new THREE.ConeGeometry(r, h, seg, 1), { y: y + h / 2, color, ry });
const blob = (tpl, x, y, z, r, color, seg, sy = 1, sx = 1) => addPart(tpl, new THREE.SphereGeometry(1, seg, Math.max(3, seg - 2)), { x, y, z, sx: r * sx, sy: r * sy, sz: r * sx, color });

// Описания пород. detail: 0 простая, 1 подробная. Высоты в метрах при среднем размере.
const BUILDERS = [
    // 0 ель: ярусы тёмных конусов
    (d) => {
        const t = createTemplate();
        trunk(t, 2.0, 0.3, 0.2, d ? 6 : 4, 0x5a3d25);
        const seg = d ? 7 : 5;
        if (d) {
            cone(t, 1.4, 3.0, 2.0, seg, 0x2c5e3a);
            cone(t, 3.1, 2.8, 1.6, seg, 0x2f6640, 0.4);
            cone(t, 4.9, 2.6, 1.2, seg, 0x33703f);
            cone(t, 6.5, 2.5, 0.8, seg, 0x38794a, 0.4);
        } else {
            cone(t, 1.4, 4.2, 2.0, seg, 0x2c5e3a);
            cone(t, 4.4, 4.0, 1.2, seg, 0x33703f);
        }
        return finish(t, 9, 0.3);
    },
    // 1 сосна: высокий рыжий ствол и плоская крона наверху
    (d) => {
        const t = createTemplate();
        trunk(t, 6.5, 0.28, 0.18, d ? 6 : 4, 0x9a5f34);
        if (d) {
            blob(t, 0, 7.0, 0, 1.7, 0x3d7a3f, 7, 0.55);
            blob(t, 0.7, 6.0, 0.4, 1.1, 0x44853f, 6, 0.6);
            blob(t, -0.6, 8.0, -0.3, 1.0, 0x3a7239, 6, 0.6);
        } else {
            blob(t, 0, 7.0, 0, 1.8, 0x3d7a3f, 5, 0.6);
        }
        return finish(t, 9, 0.28);
    },
    // 2 берёза: белый ствол с тёмными метинами, светлая крона
    (d) => {
        const t = createTemplate();
        trunk(t, 4.6, 0.2, 0.14, d ? 6 : 4, 0xf0eee6);
        if (d) {
            for (let i = 0; i < 4; i += 1) addPart(t, new THREE.CylinderGeometry(0.205, 0.205, 0.12, 6, 1), { y: 0.8 + i * 0.9, color: 0x2e2e2e });
            blob(t, 0, 5.4, 0, 1.6, 0x8fc24e, 7, 1.15);
            blob(t, 0.9, 4.6, 0.3, 1.1, 0x9bcb55, 6);
            blob(t, -0.8, 6.2, -0.2, 1.0, 0x86b948, 6);
        } else {
            blob(t, 0, 5.3, 0, 1.7, 0x8fc24e, 5, 1.15);
        }
        return finish(t, 7.5, 0.2);
    },
    // 3 осина: серо-зелёный ствол, узкая вытянутая крона
    (d) => {
        const t = createTemplate();
        trunk(t, 4.2, 0.2, 0.13, d ? 6 : 4, 0xa7ad9a);
        if (d) {
            blob(t, 0, 5.8, 0, 1.25, 0x7eb04c, 7, 1.7);
            blob(t, 0.5, 4.8, 0.2, 0.8, 0x88b954, 6, 1.2);
        } else {
            blob(t, 0, 5.8, 0, 1.3, 0x7eb04c, 5, 1.7);
        }
        return finish(t, 8.5, 0.2);
    },
    // 4 клён: невысокий, широкая округлая крона
    (d) => {
        const t = createTemplate();
        trunk(t, 2.6, 0.3, 0.22, d ? 6 : 4, 0x6d4a31);
        if (d) {
            blob(t, 0, 4.2, 0, 2.2, 0x5fa23a, 8, 0.85);
            blob(t, 1.2, 3.4, 0.5, 1.3, 0x6bb044, 6);
            blob(t, -1.1, 3.6, -0.6, 1.3, 0x57983a, 6);
        } else {
            blob(t, 0, 4.0, 0, 2.3, 0x5fa23a, 5, 0.85);
        }
        return finish(t, 6.8, 0.3);
    },
    // 5 дуб: толстый ствол, раскидистая крона из нескольких шаров
    (d) => {
        const t = createTemplate();
        trunk(t, 3.0, 0.5, 0.34, d ? 7 : 5, 0x5b4128);
        if (d) {
            blob(t, 0, 5.0, 0, 2.3, 0x4c8a34, 8, 0.8);
            blob(t, 1.9, 4.0, 0.6, 1.5, 0x55953a, 6);
            blob(t, -1.8, 4.2, -0.5, 1.6, 0x468030, 6);
            blob(t, 0.3, 6.0, 1.3, 1.3, 0x5a9b3d, 6);
        } else {
            blob(t, 0, 4.8, 0, 2.7, 0x4c8a34, 5, 0.8);
            blob(t, 1.6, 4.0, 0.5, 1.5, 0x55953a, 4);
        }
        return finish(t, 7.5, 0.5);
    },
    // 6 чёрная ольха: низкая, тёмная, сине-зелёная
    (d) => {
        const t = createTemplate();
        trunk(t, 2.2, 0.25, 0.18, d ? 6 : 4, 0x30261f);
        if (d) {
            blob(t, 0, 3.6, 0, 1.7, 0x2f5a47, 7, 0.9);
            blob(t, 0.9, 3.0, 0.4, 1.0, 0x356650, 6);
        } else {
            blob(t, 0, 3.6, 0, 1.8, 0x2f5a47, 5, 0.9);
        }
        return finish(t, 5.5, 0.25);
    },
    // 7 золотая берёза (редкая): как берёза, но золотая крона
    (d) => {
        const t = createTemplate();
        trunk(t, 5.2, 0.24, 0.16, d ? 6 : 4, 0xf6efd2);
        if (d) {
            for (let i = 0; i < 4; i += 1) addPart(t, new THREE.CylinderGeometry(0.245, 0.245, 0.12, 6, 1), { y: 0.9 + i * 1.0, color: 0x3a2f1a });
            blob(t, 0, 6.2, 0, 1.9, 0xf0c53a, 7, 1.15);
            blob(t, 1.0, 5.2, 0.3, 1.2, 0xffd54a, 6);
            blob(t, -0.9, 7.0, -0.2, 1.1, 0xe5b52f, 6);
        } else {
            blob(t, 0, 6.1, 0, 2.0, 0xf0c53a, 5, 1.15);
        }
        return finish(t, 8.7, 0.24);
    },
    // 8 древний кедр (редкий): огромный, ярусы плоских конусов, сине-зелёный
    (d) => {
        const t = createTemplate();
        trunk(t, 5.0, 0.8, 0.55, d ? 8 : 5, 0x4d3524);
        const seg = d ? 8 : 6;
        if (d) {
            cone(t, 3.2, 3.6, 3.8, seg, 0x1f5a45);
            cone(t, 5.8, 3.4, 3.0, seg, 0x23654d, 0.3);
            cone(t, 8.2, 3.2, 2.2, seg, 0x287054);
            cone(t, 10.4, 3.2, 1.4, seg, 0x2d7a5c, 0.3);
        } else {
            cone(t, 3.2, 6.2, 3.8, seg, 0x1f5a45);
            cone(t, 8.4, 5.4, 2.0, seg, 0x287054);
        }
        return finish(t, 14, 0.8);
    },
    // 9 железный дуб (редкий): тёмно-серый ствол, крона с синеватым отливом
    (d) => {
        const t = createTemplate();
        trunk(t, 3.4, 0.55, 0.38, d ? 7 : 5, 0x3a3b47);
        if (d) {
            blob(t, 0, 5.6, 0, 2.5, 0x3c5b52, 8, 0.8);
            blob(t, 2.0, 4.5, 0.6, 1.6, 0x456a60, 6);
            blob(t, -1.9, 4.7, -0.5, 1.7, 0x35524a, 6);
            blob(t, 0.3, 6.7, 1.4, 1.4, 0x4d7468, 6);
        } else {
            blob(t, 0, 5.4, 0, 2.9, 0x3c5b52, 5, 0.8);
            blob(t, 1.7, 4.5, 0.5, 1.6, 0x456a60, 4);
        }
        return finish(t, 8.5, 0.55);
    },
];

function buildBoulder(variant, detail) {
    const t = createTemplate();
    const g = new THREE.IcosahedronGeometry(1, detail ? 1 : 0);
    if (variant === 0) addPart(t, g, { y: 0.3, sx: 0.9, sy: 0.65, sz: 0.8, ry: 0.4, color: 0x8d8c86 });
    else {
        addPart(t, g, { y: 0.35, sx: 1.1, sy: 0.7, sz: 0.9, ry: 1.1, color: 0x7b7a76 });
        addPart(t, new THREE.IcosahedronGeometry(1, 0), { x: 0.9, y: 0.15, z: 0.4, sx: 0.5, sy: 0.35, sz: 0.45, color: 0x95948d });
    }
    return finish(t, 0.8, 0.9);
}

function buildOre(variant) {
    const t = createTemplate();
    addPart(t, new THREE.IcosahedronGeometry(1, 0), { y: 0.35, sx: 1.0, sy: 0.75, sz: 0.9, ry: 0.7, color: 0x5d5b5a });
    const crystal = variant === 0 ? 0xd7863a : 0x6fd0e6; // ржавая (железная) и голубая жила
    for (let i = 0; i < 3; i += 1) {
        const a = i * 2.1 + 0.3;
        addPart(t, new THREE.ConeGeometry(0.18, 0.7 + i * 0.12, 4, 1), { x: Math.cos(a) * 0.45, y: 0.65 + i * 0.05, z: Math.sin(a) * 0.45, color: crystal, ry: a });
    }
    return finish(t, 1.2, 0.9);
}

/** Геометрия пня для общего InstancedMesh: цилиндр коры и светлый срез сверху. Цвет в вершинах. */
export function createStumpGeometry() {
    const t = createTemplate();
    addPart(t, new THREE.CylinderGeometry(0.34, 0.42, 0.5, 7, 1), { y: 0.25, color: 0x6b4a2b });
    addPart(t, new THREE.CylinderGeometry(0.3, 0.3, 0.04, 7, 1), { y: 0.52, color: 0xd9b77d });
    const m = finish(t, 0.55, 0.42);
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(m.pos, 3));
    geo.setAttribute('normal', new THREE.BufferAttribute(m.nrm, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(m.col, 3));
    return geo;
}

/**
 * Все шаблоны одним объектом. trees[species][detail], boulders[variant], ores[variant].
 * Строится один раз за сессию (десятки миллисекунд).
 */
export function createModels() {
    const trees = BUILDERS.map((build) => [build(0), build(1)]);
    const boulders = [buildBoulder(0, 0), buildBoulder(1, 0)];
    const bouldersHi = [buildBoulder(0, 1), buildBoulder(1, 0)];
    const ores = [buildOre(0), buildOre(1)];
    return { trees, boulders, bouldersHi, ores };
}

export const TREE_SPECIES_COUNT = BUILDERS.length;
