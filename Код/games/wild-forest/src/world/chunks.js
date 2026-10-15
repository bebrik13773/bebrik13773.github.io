import * as THREE from '../three.js';
import { CHUNK_M, parseTreeId } from '../shared/worldgen.js';
import { WORLD } from '../config.js';
import { buildChunkData, chunkKey, collapseTree, SIZE_SCALE } from './chunk-build.js';
import { createStumpGeometry } from './models.js';

// Менеджер чанков (ДЛ-06): держит кольцо чанков вокруг героя радиусом из пресета качества.
//  - построение идёт из очереди с бюджетом времени на кадр (ближайшие первыми, минимум один чанк за кадр);
//  - далёкие чанки выгружаются с освобождением геометрии (память не растёт при долгой ходьбе);
//  - чанк это ОДИН меш (рельеф + деревья + камни) = один вызов отрисовки; пни лежат в общем InstancedMesh;
//  - срубленное дерево прячется внутри готового буфера и получает пень, без пересборки чанка;
//  - ближние чанки получают подробные модели деревьев, дальние простые (смена подробности = перестройка чанка).

/** Общий пул пней: один InstancedMesh на весь мир. */
function createStumpPool(scene, material) {
    const geometry = createStumpGeometry();
    const mesh = new THREE.InstancedMesh(geometry, material, WORLD.stumpCapacity);
    mesh.count = 0;
    mesh.frustumCulled = false; // у инстансов ограничивающая сфера считается по одной модели, поэтому отсечение отключаем
    scene.add(mesh);
    const slots = new Map();
    const free = [];
    let top = 0;
    const m = new THREE.Matrix4();
    const q = new THREE.Quaternion();
    const p = new THREE.Vector3();
    const s = new THREE.Vector3();
    return {
        mesh,
        get size() { return slots.size; },
        add(id, x, y, z, scale) {
            if (slots.has(id)) return true;
            const slot = free.length ? free.pop() : top;
            if (slot >= WORLD.stumpCapacity) return false;
            if (slot === top) top += 1;
            p.set(x, y - 0.05, z);
            s.set(scale, scale, scale);
            m.compose(p, q, s);
            mesh.setMatrixAt(slot, m);
            mesh.count = top;
            mesh.instanceMatrix.needsUpdate = true;
            slots.set(id, slot);
            return true;
        },
        remove(id) {
            const slot = slots.get(id);
            if (slot === undefined) return;
            s.set(0, 0, 0);
            p.set(0, -1000, 0);
            m.compose(p, q, s);
            mesh.setMatrixAt(slot, m);
            mesh.instanceMatrix.needsUpdate = true;
            slots.delete(id);
            free.push(slot);
        },
        dispose() {
            scene.remove(mesh);
            geometry.dispose();
            mesh.dispose();
        },
    };
}

export function createChunkManager({ scene, wg, models }) {
    const material = new THREE.MeshLambertMaterial({ vertexColors: true });
    const stumps = createStumpPool(scene, material);
    const chunks = new Map(); // key -> { cx, cz, mesh, geometry, data, lod, stumpIds }
    const felled = new Set();
    const obsIndex = new Map(); // числовой ключ чанка -> массив препятствий по клеткам (для навигации, ДЛ-08)
    const okey = (cx, cz) => (cx + 16384) * 32768 + (cz + 16384);
    const clearings = [];
    let queue = [];
    const queued = new Set();
    let preset = null;
    let radius = 5;
    let heroCx = Number.NaN;
    let heroCz = Number.NaN;
    let fx = 0; // положение героя в чанках (дробное)
    let fz = 0;
    let shadows = false;

    const distTo = (cx, cz) => Math.hypot(cx + 0.5 - fx, cz + 0.5 - fz);
    const loadRadius = () => radius + 0.3;
    const lodFor = (cx, cz) => (preset && preset.detailChunks > 0 && distTo(cx, cz) <= preset.detailChunks ? 1 : 0);

    function disposeChunk(chunk) {
        if (obsIndex.get(okey(chunk.cx, chunk.cz)) === chunk.data.obs) obsIndex.delete(okey(chunk.cx, chunk.cz));
        scene.remove(chunk.mesh);
        chunk.geometry.dispose();
        for (const id of chunk.stumpIds) stumps.remove(id);
        chunk.stumpIds = [];
    }

    function build(cx, cz) {
        const key = chunkKey(cx, cz);
        const lod = lodFor(cx, cz);
        const data = buildChunkData({ wg, models, cx, cz, preset, lod, felled, clearings });
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(data.pos, 3));
        geometry.setAttribute('normal', new THREE.BufferAttribute(data.nrm, 3));
        geometry.setAttribute('color', new THREE.BufferAttribute(data.col, 3));
        geometry.setIndex(new THREE.BufferAttribute(data.index, 1));
        geometry.computeBoundingSphere();
        const mesh = new THREE.Mesh(geometry, material);
        mesh.position.set(cx * CHUNK_M, 0, cz * CHUNK_M);
        mesh.matrixAutoUpdate = false;
        mesh.updateMatrix();
        mesh.castShadow = shadows;
        mesh.receiveShadow = shadows;

        const old = chunks.get(key);
        if (old) disposeChunk(old);
        const chunk = { cx, cz, mesh, geometry, data, lod, stumpIds: [] };
        for (const st of data.stumps) {
            if (stumps.add(st.id, st.x, st.y, st.z, st.s)) chunk.stumpIds.push(st.id);
        }
        scene.add(mesh);
        chunks.set(key, chunk);
        obsIndex.set(okey(cx, cz), data.obs);
    }

    function enqueue(cx, cz, kind) {
        const key = chunkKey(cx, cz);
        if (queued.has(key)) return;
        queued.add(key);
        queue.push({ cx, cz, kind, key });
    }

    /** Пересчёт кольца: что загрузить, что выгрузить, у кого сменилась подробность. */
    function refreshRing() {
        if (!preset) return;
        const lr = loadRadius();
        const ur = lr + WORLD.unloadMarginChunks;
        // выгрузка далёких
        for (const [key, chunk] of chunks) {
            if (distTo(chunk.cx, chunk.cz) > ur) {
                disposeChunk(chunk);
                chunks.delete(key);
            }
        }
        // нужные чанки
        const span = Math.ceil(lr) + 1;
        const baseX = Math.floor(fx);
        const baseZ = Math.floor(fz);
        for (let dz = -span; dz <= span; dz += 1) {
            for (let dx = -span; dx <= span; dx += 1) {
                const cx = baseX + dx;
                const cz = baseZ + dz;
                if (distTo(cx, cz) > lr) continue;
                const chunk = chunks.get(chunkKey(cx, cz));
                if (!chunk) enqueue(cx, cz, 'new');
                else if (chunk.lod !== lodFor(cx, cz)) enqueue(cx, cz, 'rebuild');
            }
        }
        // очередь: ближние первыми, новые раньше перестроек подробности
        queue = queue.filter((job) => {
            const keep = job.kind === 'rebuild' || distTo(job.cx, job.cz) <= ur;
            if (!keep) queued.delete(job.key);
            return keep;
        });
        queue.sort((a, b) => (a.kind === b.kind ? distTo(a.cx, a.cz) - distTo(b.cx, b.cz) : a.kind === 'new' ? -1 : 1));
    }

    function clearAll() {
        for (const chunk of chunks.values()) disposeChunk(chunk);
        chunks.clear();
        queue = [];
        queued.clear();
    }

    function rebuildLoaded() {
        for (const chunk of chunks.values()) enqueue(chunk.cx, chunk.cz, 'rebuild');
        queue.sort((a, b) => distTo(a.cx, a.cz) - distTo(b.cx, b.cz));
    }

    return {
        /** Новый пресет качества: мир перестраивается (меняются радиус, детализация рельефа и плотность леса). */
        applyPreset(next) {
            preset = next;
            radius = next.drawChunks;
            shadows = next.shadows === 'real';
            clearAll();
            if (Number.isFinite(heroCx)) refreshRing();
        },
        /** Положение героя в метрах. Пересчёт кольца только при смене чанка. */
        setCenter(x, z) {
            fx = x / CHUNK_M;
            fz = z / CHUNK_M;
            const cx = Math.floor(fx);
            const cz = Math.floor(fz);
            if (cx !== heroCx || cz !== heroCz) {
                heroCx = cx;
                heroCz = cz;
                refreshRing();
            }
        },
        /** Строит чанки из очереди, пока не вышел бюджет (но минимум один за вызов). Возвращает число построенных. */
        pump(budgetMs = WORLD.chunkBuildBudgetMs) {
            const t0 = performance.now();
            let built = 0;
            while (queue.length) {
                const job = queue.shift();
                queued.delete(job.key);
                const chunk = chunks.get(job.key);
                if (job.kind === 'new' && chunk) continue;
                if (job.kind === 'rebuild' && !chunk) continue;
                build(job.cx, job.cz);
                built += 1;
                if (performance.now() - t0 >= budgetMs) break;
            }
            return built;
        },
        /** Срубленное дерево (или добытый камень): прячем и ставим пень. Возвращает true, если чанк был на экране. */
        fell(id) {
            if (felled.has(id)) return false;
            felled.add(id);
            const parsed = parseTreeId(id);
            if (!parsed) return false;
            const chunk = chunks.get(chunkKey(parsed.cx, parsed.cz));
            if (!chunk) return false;
            chunk.data.obs[parsed.ci] = null; // срублено или добыто: больше не препятствие
            const rec = chunk.data.trees.get(id);
            if (!rec) { enqueue(parsed.cx, parsed.cz, 'rebuild'); return true; } // камень или руда: перестраиваем чанк
            collapseTree(chunk.data.pos, rec);
            chunk.geometry.attributes.position.needsUpdate = true;
            chunk.data.trees.delete(id);
            chunk.data.treeCount -= 1;
            if (stumps.add(id, rec.wx, rec.y, rec.wz, SIZE_SCALE[rec.size])) chunk.stumpIds.push(id);
            return true;
        },
        /** Откат рубки, которую сервер не принял: дерево возвращается (чанк перестраивается). */
        unfell(id) {
            if (!felled.delete(id)) return false;
            stumps.remove(id);
            const parsed = parseTreeId(id);
            if (parsed) enqueue(parsed.cx, parsed.cz, 'rebuild');
            return true;
        },
        /** Расчищенный круг (поляна, просека): деревья и камни внутри пропадают. */
        addClearing(x, z, r) {
            clearings.push({ x, z, r });
            const reach = (r + 3) / CHUNK_M;
            for (const chunk of chunks.values()) {
                if (Math.abs(chunk.cx + 0.5 - x / CHUNK_M) <= reach + 0.5 && Math.abs(chunk.cz + 0.5 - z / CHUNK_M) <= reach + 0.5) {
                    enqueue(chunk.cx, chunk.cz, 'rebuild');
                }
            }
        },
        /** Полный набор изменений мира с сервера (при входе): заменяет локальный список и перестраивает видимое. */
        setChanges({ felled: ids = [], clearings: circles = [] } = {}) {
            felled.clear();
            ids.forEach((id) => felled.add(id));
            clearings.length = 0;
            circles.forEach((c) => clearings.push({ x: c.x, z: c.z, r: c.r }));
            rebuildLoaded();
        },
        setShadows(on) {
            shadows = Boolean(on);
            for (const chunk of chunks.values()) {
                chunk.mesh.castShadow = shadows;
                chunk.mesh.receiveShadow = shadows;
            }
        },
        /** Препятствие в клетке (глобальные индексы клеток 4×4 м) или null. */
        obstacleInCell(gx, gz) {
            const cx = Math.floor(gx / 8);
            const cz = Math.floor(gz / 8);
            const arr = obsIndex.get(okey(cx, cz));
            return arr ? arr[(gz - cz * 8) * 8 + (gx - cx * 8)] : null;
        },
        /** Попадает ли круг (x, z, r) в какое-либо препятствие (стволы, камни, руда). Деревья и камни только в загруженных чанках. */
        blockedAt(x, z, r) {
            const gx = Math.floor(x / 4);
            const gz = Math.floor(z / 4);
            for (let dz = -1; dz <= 1; dz += 1) {
                for (let dx = -1; dx <= 1; dx += 1) {
                    const o = this.obstacleInCell(gx + dx, gz + dz);
                    if (!o) continue;
                    const ex = x - o.x;
                    const ez = z - o.z;
                    const m = r + o.r;
                    if (ex * ex + ez * ez < m * m) return true;
                }
            }
            return false;
        },
        /** Препятствия рядом с точкой (клетки вокруг, достаточно для радиусов до ~2 м). */
        forEachNear(x, z, range, cb) {
            const span = Math.max(1, Math.ceil((range + 1.5) / 4));
            const gx = Math.floor(x / 4);
            const gz = Math.floor(z / 4);
            for (let dz = -span; dz <= span; dz += 1) {
                for (let dx = -span; dx <= span; dx += 1) {
                    const o = this.obstacleInCell(gx + dx, gz + dz);
                    if (o) cb(o);
                }
            }
        },
        /** Все препятствия в квадрате range вокруг точки (для выбора объекта пальцем). */
        forEachInRange(x, z, range, cb) {
            const x0 = Math.floor((x - range) / CHUNK_M);
            const x1 = Math.floor((x + range) / CHUNK_M);
            const z0 = Math.floor((z - range) / CHUNK_M);
            const z1 = Math.floor((z + range) / CHUNK_M);
            for (let cz = z0; cz <= z1; cz += 1) {
                for (let cx = x0; cx <= x1; cx += 1) {
                    const arr = obsIndex.get(okey(cx, cz));
                    if (!arr) continue;
                    for (let i = 0; i < 64; i += 1) {
                        const o = arr[i];
                        if (o && Math.abs(o.x - x) <= range && Math.abs(o.z - z) <= range) cb(o);
                    }
                }
            }
        },
        get material() { return material; },
        get loaded() { return chunks.size; },
        get pending() { return queue.length; },
        get stumpCount() { return stumps.size; },
        get treeCount() {
            let n = 0;
            for (const chunk of chunks.values()) n += chunk.data.treeCount;
            return n;
        },
        has: (cx, cz) => chunks.has(chunkKey(cx, cz)),
        chunk: (cx, cz) => chunks.get(chunkKey(cx, cz)) || null,
        dispose() {
            clearAll();
            stumps.dispose();
            material.dispose();
        },
    };
}
