import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from '../src/three.js';
import { createBeaver } from '../src/entities/beaver.js';
import { buildBeaverData, createBeaverGeometries, weldNormals, TRIANGLE_BUDGET, REST, PARENT } from '../src/entities/beaver-model.js';
import { BONES } from '../src/entities/beaver-anim.js';
import { DEFAULT_SKIN, SAMPLE_SKINS, resolveSkin, resolveGear, PALETTE_KEYS, ACCESSORY_KINDS } from '../src/entities/skins.js';

const MAX_GEAR = { axe: 6, pack: 8, lamp: 4 };

test('бюджет треугольников: даже с максимальной экипировкой и всеми аксессуарами', () => {
    for (const detail of ['high', 'low']) {
        for (const skin of SAMPLE_SKINS) {
            const d = buildBeaverData({ detail, skin, gear: MAX_GEAR });
            assert.ok(d.triangles <= TRIANGLE_BUDGET[detail], `${detail}/${skin.id}: ${d.triangles} > ${TRIANGLE_BUDGET[detail]}`);
        }
    }
    const base = buildBeaverData({ detail: 'low' });
    assert.ok(base.triangles < buildBeaverData({ detail: 'high' }).triangles);
});

test('данные модели целые: конечные числа, единичные нормали, веса и индексы костей верны', () => {
    const d = buildBeaverData({ detail: 'high', skin: SAMPLE_SKINS[1], gear: MAX_GEAR });
    assert.ok(d.positions.every(Number.isFinite) && d.normals.every(Number.isFinite) && d.colors.every(Number.isFinite));
    for (let i = 0; i < d.vertices; i += 1) {
        const len = Math.hypot(d.normals[i * 3], d.normals[i * 3 + 1], d.normals[i * 3 + 2]);
        assert.ok(Math.abs(len - 1) < 1e-3, `нормаль ${i}: ${len}`);
        assert.equal(d.skinWeight[i * 4], 1);
        assert.ok(d.skinIndex[i * 4] < BONES.length);
    }
    for (const idx of d.index) assert.ok(idx < d.vertices);
    assert.equal(d.index.length % 3, 0);
});

test('рост и габариты: бобёр стоит на земле, высота около 1.3 м, хвост сзади', () => {
    const d = buildBeaverData({ detail: 'high' });
    let minY = 9; let maxY = -9; let minZ = 9; let maxZ = -9;
    for (let i = 0; i < d.vertices; i += 1) {
        const y = d.positions[i * 3 + 1]; const z = d.positions[i * 3 + 2];
        minY = Math.min(minY, y); maxY = Math.max(maxY, y); minZ = Math.min(minZ, z); maxZ = Math.max(maxZ, z);
    }
    assert.ok(minY > -0.01 && minY < 0.05, `низ ${minY}`);
    assert.ok(maxY > 1.25 && maxY < 1.5, `верх ${maxY}`);
    assert.ok(minZ < -0.9, `хвост ${minZ}`);
    assert.ok(maxZ > 0.4, `нос ${maxZ}`);
});

test('скелет: у каждой кости есть родитель и положение покоя', () => {
    for (const name of BONES) {
        assert.ok(REST[name], name);
        assert.ok(name in PARENT);
    }
    assert.equal(PARENT.root, null);
});

test('сварка нормалей контура: вершины в одной точке получают одну нормаль', () => {
    const d = buildBeaverData({ detail: 'high' });
    const w = weldNormals(d.positions, d.normals);
    const seen = new Map();
    for (let i = 0; i < d.vertices; i += 1) {
        const k = `${Math.round(d.positions[i * 3] * 1000)},${Math.round(d.positions[i * 3 + 1] * 1000)},${Math.round(d.positions[i * 3 + 2] * 1000)}`;
        const n = [w[i * 3], w[i * 3 + 1], w[i * 3 + 2]];
        if (seen.has(k)) { const p = seen.get(k); assert.ok(Math.abs(p[0] - n[0]) + Math.abs(p[1] - n[1]) + Math.abs(p[2] - n[2]) < 1e-5); } else seen.set(k, n);
    }
    const { body, hull } = createBeaverGeometries(d);
    assert.equal(hull.attributes.position, body.attributes.position, 'позиции общие, память не удваивается');
    assert.notEqual(hull.attributes.normal, body.attributes.normal);
});

test('снаряжение меняет модель: рюкзак растёт по уровням, фонарь добавляет детали', () => {
    const tri = (gear) => buildBeaverData({ detail: 'high', gear }).triangles;
    assert.ok(tri({ pack: 0 }) < tri({ pack: 1 }));
    assert.ok(tri({ pack: 1 }) < tri({ pack: 3 }));
    assert.ok(tri({ pack: 3 }) < tri({ pack: 5 }));
    assert.ok(tri({ pack: 5 }) < tri({ pack: 8 }));
    assert.ok(tri({ lamp: 0 }) < tri({ lamp: 1 }));
    const heightOf = (gear) => { const d = buildBeaverData({ gear }); let m = 0; for (let i = 0; i < d.vertices; i += 1) m = Math.max(m, d.positions[i * 3 + 1]); return m; };
    assert.ok(Math.abs(heightOf({ pack: 8 }) - heightOf({ pack: 1 })) < 0.5);
});

test('скин меняет цвета, а не форму; аксессуары добавляют части', () => {
    const classic = buildBeaverData({ skin: DEFAULT_SKIN });
    const golden = buildBeaverData({ skin: SAMPLE_SKINS.find((s) => s.id === 'golden') });
    assert.notDeepEqual(Array.from(classic.colors.slice(0, 60)), Array.from(golden.colors.slice(0, 60)));
    const naked = buildBeaverData({ skin: { ...DEFAULT_SKIN, accessories: { hat: null, scarf: null, glasses: null } } });
    for (const slot of Object.keys(ACCESSORY_KINDS)) {
        for (const kind of ACCESSORY_KINDS[slot]) {
            const d = buildBeaverData({ skin: { accessories: { [slot]: { kind, color: 0x112233, color2: 0xffffff } } } });
            assert.ok(d.triangles > naked.triangles, `${slot}/${kind}`);
        }
    }
});

test('разбор скина: неполный и битый скин приводятся к рабочему', () => {
    const s = resolveSkin({ id: 'x', palette: { fur: 0xff0000, belly: 'не цвет', nose: -5 }, accessories: { hat: { kind: 'шляпа-невидимка' }, scarf: { kind: 'wool', color: 'красный' } } });
    assert.equal(s.palette.fur, 0xff0000);
    for (const key of PALETTE_KEYS) assert.ok(Number.isInteger(s.palette[key]));
    assert.equal(s.accessories.hat, null);
    assert.equal(s.accessories.scarf.kind, 'wool');
    assert.ok(Number.isInteger(s.accessories.scarf.color));
    assert.deepEqual(resolveSkin(null).palette, resolveSkin(DEFAULT_SKIN).palette);
    assert.deepEqual(JSON.parse(JSON.stringify(SAMPLE_SKINS)), JSON.parse(JSON.stringify(SAMPLE_SKINS)), 'скины сериализуются в JSON');
});

test('разбор снаряжения: границы уровней', () => {
    assert.deepEqual(resolveGear({ axe: 99, pack: -4, lamp: 2.4 }), { axe: 6, pack: 0, lamp: 2 });
    assert.deepEqual(resolveGear(undefined), { axe: 1, pack: 1, lamp: 0 });
    assert.deepEqual(resolveGear({ axe: 0 }), { axe: 1, pack: 1, lamp: 0 });
});

test('createBeaver: два вызова отрисовки, подмена скина, снаряжения и подробности на лету', () => {
    const b = createBeaver({ detail: 'high' });
    let info = b.info();
    assert.equal(info.drawCalls, 2);
    assert.equal(info.bones, 13);
    const tri0 = info.triangles;
    b.setGear({ lamp: 3 });
    info = b.info();
    assert.equal(info.drawCalls, 3, 'светящийся шар фонаря');
    assert.ok(info.triangles > tri0);
    b.setSkin(SAMPLE_SKINS[1]);
    assert.equal(b.info().skin, 'winter');
    assert.equal(b.setDetail('low'), true);
    assert.equal(b.setDetail('low'), false);
    assert.ok(b.info().triangles <= TRIANGLE_BUDGET.low);
    b.setOutlineVisible(false);
    assert.equal(b.info().hullTriangles, 0);
    b.dispose();
});

test('createBeaver: анимация двигает кости, скрытые предметы сжаты, поза конечна', () => {
    const events = [];
    const b = createBeaver({ onEvent: (e) => events.push(e.type) });
    const root = b.group.getObjectByName('hips');
    const y0 = root.position.y;
    b.setState('walk'); b.setSpeed(3.2);
    for (let i = 0; i < 120; i += 1) b.update(1 / 60);
    b.group.updateMatrixWorld(true);
    const legL = b.group.getObjectByName('legL');
    assert.ok(Math.abs(legL.rotation.x) > 0.05, 'нога качается');
    assert.ok(Number.isFinite(root.position.y) && Math.abs(root.position.y - y0) < 0.1);
    const logs = b.group.getObjectByName('logs');
    assert.ok(logs.scale.x < 0.01, 'брёвна скрыты, пока не несёт');
    b.setState('carry');
    for (let i = 0; i < 30; i += 1) b.update(1 / 60);
    assert.ok(logs.scale.x > 0.99, 'брёвна появились');
    assert.ok(b.group.getObjectByName('axe').scale.x < 0.01, 'топор убран');
    b.setState('chop', { auto: true, rate: 2 });
    for (let i = 0; i < 180; i += 1) b.update(1 / 60);
    assert.ok(events.filter((e) => e === 'hit').length >= 5);
    b.setState('sleep'); // в Node нет холста, значки пропускаются без ошибок
    for (let i = 0; i < 30; i += 1) b.update(1 / 60);
    b.dispose();
});

test('материал контура раздувает оболочку после скининга', () => {
    const b = createBeaver();
    const hull = b.group.getObjectByName('beaver-outline');
    assert.equal(hull.material.side, THREE.BackSide);
    const shader = { uniforms: {}, vertexShader: '#include <common>\nvoid main(){\n#include <skinning_vertex>\n}' };
    hull.material.onBeforeCompile(shader);
    assert.ok(shader.vertexShader.includes('uniform float uOutline'));
    assert.ok(shader.vertexShader.includes('transformed += objectNormal * uOutline'));
    b.setOutlineWidth(0.05);
    assert.equal(shader.uniforms.uOutline.value, 0.05);
    b.dispose();
});
