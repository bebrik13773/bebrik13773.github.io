import * as THREE from '../three.js';
import { BONES, B, CH, rot, STATE_NAMES, createBeaverAnimator } from './beaver-anim.js';
import {
    REST, PARENT, LAMP_POINT, lampGlowRadius, buildBeaverData, createBeaverGeometries, createOutlineMaterial, getToonGradient,
} from './beaver-model.js';
import { DEFAULT_SKIN, resolveSkin, resolveGear } from './skins.js';

// 3D-бобёр (ДЛ-07): скелет из 13 костей, один скинированный меш и оболочка контура (2 вызова отрисовки),
// плюс светящийся шар фонаря при наличии фонаря. Анимация считается в beaver-anim.js, здесь поза кладётся в кости.

const HIDDEN_SCALE = 0.0001; // «скрытый» предмет: кость сжата почти до нуля

// Значки над головой: «z» во сне и «!» при испуге. Рисуются один раз на холсте, создаются только когда нужны.
const glyphTextures = new Map();
function glyphTexture(char, fill) {
    const key = char + fill;
    if (glyphTextures.has(key)) return glyphTextures.get(key);
    if (typeof document === 'undefined') return null;
    const canvas = document.createElement('canvas');
    canvas.width = 64; canvas.height = 64;
    const ctx = canvas.getContext('2d');
    ctx.font = 'bold 54px sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.lineWidth = 8; ctx.strokeStyle = '#1a1210'; ctx.strokeText(char, 32, 34);
    ctx.fillStyle = fill; ctx.fillText(char, 32, 34);
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    glyphTextures.set(key, tex);
    return tex;
}

/**
 * Создаёт бобра. options: skin (описание скина), gear ({ axe, pack, lamp }), detail ('high' | 'low'),
 * outline (рисовать контур), onEvent (получает { type: 'hit' } в момент удара топором).
 */
export function createBeaver({ skin = DEFAULT_SKIN, gear, detail = 'high', outline = true, onEvent } = {}) {
    const group = new THREE.Group();
    group.name = 'beaver';

    // кости: положение относительно родителя, как в покое
    const bones = BONES.map((name) => { const bone = new THREE.Bone(); bone.name = name; return bone; });
    BONES.forEach((name, i) => {
        const p = PARENT[name];
        const r = REST[name];
        if (p) {
            const pr = REST[p];
            bones[i].position.set(r[0] - pr[0], r[1] - pr[1], r[2] - pr[2]);
            bones[B[p]].add(bones[i]);
        } else {
            bones[i].position.set(r[0], r[1], r[2]);
        }
    });
    bones[0].updateMatrixWorld(true);
    const skeleton = new THREE.Skeleton(bones);

    const bodyMaterial = new THREE.MeshToonMaterial({ vertexColors: true, gradientMap: getToonGradient() });
    const outlineUniform = { value: 0.022 };
    const hullMaterial = createOutlineMaterial(outlineUniform);

    const mesh = new THREE.SkinnedMesh(new THREE.BufferGeometry(), bodyMaterial);
    mesh.name = 'beaver-body';
    mesh.frustumCulled = false;
    mesh.add(bones[0]);
    group.add(mesh);
    mesh.updateMatrixWorld(true);
    mesh.bind(skeleton);

    const hull = new THREE.SkinnedMesh(new THREE.BufferGeometry(), hullMaterial);
    hull.name = 'beaver-outline';
    hull.frustumCulled = false;
    hull.visible = Boolean(outline);
    group.add(hull);
    hull.bind(skeleton, mesh.bindMatrix);

    // фонарь: подвес (сюда ДЛ-28 прикрепит точечный свет) и светящийся шар
    const lampPivot = new THREE.Object3D();
    lampPivot.name = 'beaver-lamp';
    const torsoRest = REST.torso;
    lampPivot.position.set(LAMP_POINT[0] - torsoRest[0], LAMP_POINT[1] - torsoRest[1], LAMP_POINT[2] - torsoRest[2]);
    bones[B.torso].add(lampPivot);
    const glow = new THREE.Mesh(new THREE.SphereGeometry(1, 8, 6), new THREE.MeshBasicMaterial({ color: 0xfff0a8, fog: false }));
    glow.visible = false;
    lampPivot.add(glow);

    const state = { skin: resolveSkin(skin), gear: resolveGear(gear), detail: detail === 'low' ? 'low' : 'high' };
    let stats = { triangles: 0, vertices: 0 };

    function rebuild() {
        const data = buildBeaverData({ detail: state.detail, skin: state.skin, gear: state.gear });
        const geos = createBeaverGeometries(data);
        mesh.geometry.dispose();
        hull.geometry.dispose();
        mesh.geometry = geos.body;
        hull.geometry = geos.hull;
        stats = { triangles: data.triangles, vertices: data.vertices };
        glow.visible = state.gear.lamp > 0;
        glow.scale.setScalar(lampGlowRadius(state.gear.lamp));
    }
    rebuild();

    // значки
    const effects = new THREE.Group();
    effects.name = 'beaver-effects';
    group.add(effects);
    const sprites = [];
    function ensureSprites() {
        if (sprites.length || typeof document === 'undefined') return sprites.length > 0;
        const z = glyphTexture('z', '#bfe3ff');
        const bang = glyphTexture('!', '#ffd23a');
        if (!z || !bang) return false;
        for (let i = 0; i < 3; i += 1) sprites.push({ kind: 'z', sprite: new THREE.Sprite(new THREE.SpriteMaterial({ map: z, transparent: true, depthWrite: false, fog: false })), age: i * 0.9 });
        sprites.push({ kind: 'bang', sprite: new THREE.Sprite(new THREE.SpriteMaterial({ map: bang, transparent: true, depthWrite: false, fog: false })), age: 0 });
        sprites.forEach((s) => { s.sprite.visible = false; effects.add(s.sprite); });
        return true;
    }

    const animator = createBeaverAnimator({ onEvent });
    const pose = animator.pose;
    let shown = null; // что показывают значки сейчас

    function applyPose() {
        for (let i = 0; i < BONES.length; i += 1) {
            bones[i].rotation.set(pose[i * 3], pose[i * 3 + 1], pose[i * 3 + 2]);
        }
        const hips = bones[B.hips];
        const hr = REST.hips;
        hips.position.set(hr[0] + pose[CH.HIPS_X], hr[1] + pose[CH.HIPS_Y], hr[2] + pose[CH.HIPS_Z]);
        bones[B.root].position.set(0, pose[CH.ROOT_Y], 0);
        const sq = pose[CH.SQUASH];
        hips.scale.set(1 + 0.04 * sq, 1 - 0.09 * sq, 1 + 0.04 * sq);
        bones[B.axe].scale.setScalar(Math.max(pose[CH.VIS_AXE], HIDDEN_SCALE));
        bones[B.logs].scale.setScalar(Math.max(pose[CH.VIS_LOGS], HIDDEN_SCALE));
        bones[B.food].scale.setScalar(Math.max(pose[CH.VIS_FOOD], HIDDEN_SCALE));
    }

    function updateEffects(dt) {
        const want = animator.state === 'sleep' ? 'z' : animator.state === 'scared' ? 'bang' : null;
        if (want && !ensureSprites()) return;
        if (!sprites.length) return;
        for (const s of sprites) {
            if (s.kind !== want) { s.sprite.visible = false; continue; }
            s.sprite.visible = true;
            s.age += dt;
            if (want === 'z') {
                const k = (s.age % 2.7) / 2.7; // «z» поднимаются и тают
                s.sprite.position.set(0.25 + 0.22 * k, 1.35 + 0.55 * k, 0.1);
                s.sprite.scale.setScalar(0.2 + 0.2 * k);
                s.sprite.material.opacity = k < 0.15 ? k / 0.15 : 1 - Math.max(0, (k - 0.6) / 0.4);
            } else {
                const pop = Math.min(1, s.age / 0.12);
                s.sprite.position.set(0, 1.78 + 0.04 * Math.sin(s.age * 14), 0.05);
                s.sprite.scale.setScalar(0.42 * (0.4 + 0.6 * pop));
                s.sprite.material.opacity = 1;
            }
        }
        if (shown !== want) { shown = want; sprites.forEach((s) => { s.age = s.kind === 'z' ? sprites.indexOf(s) * 0.9 : 0; }); }
    }

    applyPose();

    return {
        group,
        lampPivot,
        get state() { return animator.state; },
        get skin() { return state.skin; },
        get gear() { return state.gear; },
        get detail() { return state.detail; },
        get animator() { return animator; },
        setState(next, opts) { return animator.setState(next, opts); },
        setSpeed(mps) { animator.setSpeed(mps); },
        setLoad(v) { animator.setLoad(v); },
        /** Один взмах топором (по тапу). Событие hit придёт в onEvent в момент удара. */
        swing() { animator.swing(); },
        update(dt) {
            animator.update(dt);
            applyPose();
            updateEffects(Math.min(Math.max(dt, 0), 0.1));
        },
        setSkin(next) { state.skin = resolveSkin(next); rebuild(); },
        setGear(next) { state.gear = resolveGear({ ...state.gear, ...(next || {}) }); rebuild(); },
        setDetail(next) {
            const d = next === 'low' ? 'low' : 'high';
            if (d === state.detail) return false;
            state.detail = d;
            rebuild();
            return true;
        },
        /** Толщина контура в метрах. Сцена увеличивает её с расстоянием до камеры, чтобы линия не пропадала вдали. */
        setOutlineWidth(w) { outlineUniform.value = Math.max(0, w); },
        setOutlineVisible(v) { hull.visible = Boolean(v); },
        info() {
            return {
                state: animator.state,
                detail: state.detail,
                triangles: stats.triangles,
                hullTriangles: hull.visible ? stats.triangles : 0,
                bones: BONES.length,
                drawCalls: 1 + (hull.visible ? 1 : 0) + (glow.visible ? 1 : 0),
                skin: state.skin.id,
                gear: { ...state.gear },
            };
        },
        dispose() {
            mesh.geometry.dispose();
            hull.geometry.dispose();
            bodyMaterial.dispose();
            hullMaterial.dispose();
            glow.geometry.dispose();
            glow.material.dispose();
            sprites.forEach((s) => s.sprite.material.dispose());
            skeleton.dispose();
        },
    };
}

export { STATE_NAMES, rot };
