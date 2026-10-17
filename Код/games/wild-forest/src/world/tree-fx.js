import * as THREE from '../three.js';
import { MOVE } from '../config.js';

// Живое дерево под топором (#61). Дерево в буфере чанка нельзя двигать, поэтому на время рубки оно снимается с чанка
// (chunks.detachTree) и показывается отдельным мешем: дрожит от ударов, на стволе растёт зарубка со светлым срезом,
// от удара летят щепки, а когда шкала заполнена, дерево падает от бобра и через секунду уходит под землю.
// Пень ставит менеджер чанков (chunks.fell) в тот же момент, когда дерево начинает падать.

const CHIP_COLOR = 0xe3c18a;
const NOTCH_COLOR = 0xe8cf9a;
const FALL_SECONDS = 1.45;       // время падения
const LIE_SECONDS = 1.1;         // сколько лежит, прежде чем уйти под землю
const SINK_SECONDS = 0.6;
const MAX_CHIPS = 24;

const _axis = new THREE.Vector3();
const _quat = new THREE.Quaternion();

/** Строит геометрию дерева из шаблона модели: поворот и масштаб как в чанке, цвет с тем же оттенком. */
function treeGeometry(tpl, info) {
    const cosA = Math.cos(info.angle);
    const sinA = Math.sin(info.angle);
    const n = tpl.verts;
    const pos = new Float32Array(n * 3);
    const nrm = new Float32Array(n * 3);
    const col = new Float32Array(n * 3);
    for (let k = 0; k < n; k += 1) {
        const o = k * 3;
        const px = tpl.pos[o];
        const pz = tpl.pos[o + 2];
        pos[o] = (px * cosA + pz * sinA) * info.scale;
        pos[o + 1] = tpl.pos[o + 1] * info.scale;
        pos[o + 2] = (-px * sinA + pz * cosA) * info.scale;
        const nx = tpl.nrm[o];
        const nz = tpl.nrm[o + 2];
        nrm[o] = nx * cosA + nz * sinA;
        nrm[o + 1] = tpl.nrm[o + 1];
        nrm[o + 2] = -nx * sinA + nz * cosA;
        col[o] = tpl.col[o] * info.tint; col[o + 1] = tpl.col[o + 1] * info.tint; col[o + 2] = tpl.col[o + 2] * info.tint;
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geo.setAttribute('normal', new THREE.BufferAttribute(nrm, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(col, 3));
    geo.computeBoundingSphere();
    return geo;
}

export function createTreeFx({ scene, chunks, models, groundY = () => 0, now = () => performance.now() }) {
    const entries = new Map(); // id -> запись
    const chipGeo = new THREE.BoxGeometry(0.09, 0.05, 0.06);
    const chipMat = new THREE.MeshLambertMaterial({ color: CHIP_COLOR });
    const notchGeo = new THREE.BoxGeometry(1, 1, 1);
    const notchMat = new THREE.MeshLambertMaterial({ color: NOTCH_COLOR });
    const chips = [];
    for (let i = 0; i < MAX_CHIPS; i += 1) {
        const mesh = new THREE.Mesh(chipGeo, chipMat);
        mesh.visible = false;
        scene.add(mesh);
        chips.push({ mesh, vx: 0, vy: 0, vz: 0, life: 0, spin: 0 });
    }
    let chipCursor = 0;

    function spawnChips(x, y, z, dx, dz, count) {
        for (let i = 0; i < count; i += 1) {
            const c = chips[chipCursor];
            chipCursor = (chipCursor + 1) % MAX_CHIPS;
            c.mesh.visible = true;
            c.mesh.position.set(x + (Math.random() - 0.5) * 0.2, y + (Math.random() - 0.5) * 0.12, z + (Math.random() - 0.5) * 0.2);
            const out = 0.9 + Math.random() * 1.6;
            c.vx = dx * out + (Math.random() - 0.5) * 1.2;
            c.vz = dz * out + (Math.random() - 0.5) * 1.2;
            c.vy = 1.2 + Math.random() * 1.8;
            c.life = 0.55 + Math.random() * 0.35;
            c.spin = (Math.random() - 0.5) * 18;
            c.mesh.rotation.set(Math.random() * 3, Math.random() * 3, Math.random() * 3);
        }
    }

    function removeEntry(id) {
        const e = entries.get(id);
        if (!e) return;
        scene.remove(e.pivot);
        e.geometry.dispose();
        entries.delete(id);
    }

    /** Применяет наклон пивота: lean (рад) в сторону от бобра. */
    function setLean(e, angle) {
        _axis.set(e.dirZ, 0, -e.dirX); // вращение вокруг этой оси наклоняет верхушку в сторону (dirX, dirZ)
        _quat.setFromAxisAngle(_axis, angle);
        e.pivot.quaternion.copy(_quat);
    }

    return {
        get active() { return entries.size; },
        has: (id) => entries.has(id),
        isStanding: (id) => { const e = entries.get(id); return Boolean(e) && e.mode === 'stand'; },
        /** Начать рубку: дерево снимается с чанка и показывается мешем. Возвращает true, если получилось. */
        begin(id, heroX, heroZ) {
            if (entries.has(id)) return true;
            const info = chunks.detachTree(id);
            if (!info) return false;
            const tpl = models.trees[info.sp][info.lod];
            const geometry = treeGeometry(tpl, info);
            const mesh = new THREE.Mesh(geometry, chunks.material);
            const pivot = new THREE.Group();
            pivot.position.set(info.x, info.y - 0.1, info.z);
            pivot.add(mesh);
            scene.add(pivot);
            let dx = info.x - heroX;
            let dz = info.z - heroZ;
            const len = Math.hypot(dx, dz) || 1;
            dx /= len; dz /= len; // от бобра к дереву: сюда дерево и упадёт
            const trunkR = MOVE.treeRadius[info.size];
            // зарубка: светлый срез на стволе с той стороны, где стоит бобёр
            const notch = new THREE.Mesh(notchGeo, notchMat);
            notch.position.set(-dx * trunkR * 0.82, 0.95, -dz * trunkR * 0.82);
            notch.rotation.y = Math.atan2(-dx, -dz);
            notch.visible = false;
            pivot.add(notch);
            entries.set(id, {
                id, info, pivot, mesh, geometry, notch, trunkR, dirX: dx, dirZ: dz,
                mode: 'stand', frac: 0, shake: 0, t: 0, delay: 0, landed: false, scale: 1,
            });
            return true;
        },
        /** Прогресс рубки 0..1 (для зарубки и лёгкого наклона). */
        setProgress(id, frac) {
            const e = entries.get(id);
            if (e && e.mode === 'stand') e.frac = Math.max(0, Math.min(1, frac));
        },
        /** Удар топором: дерево вздрагивает, летят щепки, зарубка растёт. */
        impact(id) {
            const e = entries.get(id);
            if (!e || e.mode !== 'stand') return false;
            e.shake = Math.min(0.07, e.shake + 0.04);
            e.t = 0;
            const p = e.pivot.position;
            spawnChips(p.x - e.dirX * e.trunkR, p.y + 0.95, p.z - e.dirZ * e.trunkR, -e.dirX, -e.dirZ, 5);
            return true;
        },
        /** Бобёр ушёл, не дорубив: дерево возвращается в чанк. */
        cancel(id) {
            const e = entries.get(id);
            if (!e || e.mode !== 'stand') return false;
            removeEntry(id);
            chunks.attachTree(id);
            return true;
        },
        /** Дерево срублено: через delay секунд падает от бобра. Пень появляется сразу (chunks.fell зовёт вызывающий). */
        fall(id, heroX, heroZ, delay = 0.22) {
            let e = entries.get(id);
            if (!e) {
                if (!this.begin(id, heroX, heroZ)) return false;
                e = entries.get(id);
            }
            if (e.mode !== 'stand') return false;
            e.mode = 'fall'; e.t = -delay; e.notch.visible = true; e.notch.scale.set(e.trunkR * 1.1, 0.34, 0.16);
            return true;
        },
        update(dt) {
            const step = Math.min(0.1, Math.max(0, dt));
            for (const e of [...entries.values()]) {
                if (e.mode === 'stand') {
                    e.t += step;
                    e.shake *= Math.exp(-step / 0.28);
                    const sway = e.shake * Math.sin(e.t * 30);
                    setLean(e, 0.05 * e.frac + sway);
                    if (e.frac > 0.02) {
                        e.notch.visible = true;
                        const f = e.frac;
                        e.notch.scale.set(e.trunkR * (0.5 + 0.7 * f), 0.08 + 0.26 * f, 0.07 + 0.09 * f);
                    }
                } else {
                    e.t += step;
                    if (e.t < 0) { // короткая пауза до падения: ещё дрожит
                        setLean(e, 0.06 * Math.sin(e.t * 40));
                        continue;
                    }
                    if (e.t < FALL_SECONDS) {
                        const k = e.t / FALL_SECONDS;
                        setLean(e, (Math.PI / 2) * (k * k * (0.6 + 0.4 * k)));
                    } else {
                        const k = e.t - FALL_SECONDS;
                        if (!e.landed) { e.landed = true; e.landT = e.t; }
                        const rest = Math.PI / 2 - 0.05 * Math.exp(-k * 7) * Math.cos(k * 22); // отскок
                        setLean(e, rest);
                        if (k > LIE_SECONDS) {
                            const s = 1 - (k - LIE_SECONDS) / SINK_SECONDS;
                            if (s <= 0) { removeEntry(e.id); continue; }
                            e.pivot.scale.setScalar(Math.max(0.001, s));
                        }
                    }
                }
            }
            for (const c of chips) {
                if (!c.mesh.visible) continue;
                c.life -= step;
                if (c.life <= 0) { c.mesh.visible = false; continue; }
                c.vy -= 9.8 * step;
                const p = c.mesh.position;
                p.x += c.vx * step; p.y += c.vy * step; p.z += c.vz * step;
                const g = groundY(p.x, p.z) + 0.03;
                if (p.y < g) { p.y = g; c.vy = 0; c.vx *= 0.5; c.vz *= 0.5; }
                c.mesh.rotation.x += c.spin * step;
                c.mesh.rotation.z += c.spin * 0.6 * step;
            }
        },
        /** Для тестов: состояние дерева. */
        info(id) { const e = entries.get(id); return e ? { mode: e.mode, frac: e.frac, shake: e.shake, t: e.t } : null; },
        dispose() {
            for (const id of [...entries.keys()]) { const e = entries.get(id); if (e && e.mode === 'stand') chunks.attachTree(id); removeEntry(id); }
            chips.forEach((c) => scene.remove(c.mesh));
            chipGeo.dispose(); chipMat.dispose(); notchGeo.dispose(); notchMat.dispose();
        },
    };
}
