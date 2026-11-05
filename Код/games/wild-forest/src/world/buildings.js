import { createBuildingView } from './building-view.js';
import { buildStage, findSpec } from '../shared/build.js';

// Постройки в мире (ДЛ-16): виды, цели для тапа, непроходимые пятачки, призрак. Состояние (прогресс, стадия) приходит от сервера,
// flow добавляет к нему ещё не подтверждённые тапы (показ), а здесь только рисуем и держим цели.
export function createBuildings({ scene, chunks, groundY, addTarget, removeTarget, catalog = null }) {
    const items = new Map();        // id → { data, view, shown }
    let specs = catalog;
    let ghost = null;               // { view, type }
    let outline = true;             // чёрный контур у построек (на слабом качестве выключаем)
    const targetId = (id) => `bld:${id}`;

    const specOf = (type) => findSpec(specs, type) || { r: 2.8, h: 3.5 };

    function place(view, x, z, rot) {
        view.group.position.set(x, groundY(x, z), z);
        view.group.rotation.y = -(rot * Math.PI) / 180;
    }

    function addBlockers(it) {
        const { x, z } = it.data;
        const r = specOf(it.data.type).r;
        chunks.addBlocker(x, z, r * 0.92);
    }

    function refreshTarget(it) {
        const s = specOf(it.data.type);
        const { id, x, z } = it.data;
        addTarget({ id: targetId(id), kind: 'building', buildingId: id, x, y: groundY(x, z), z, h: s.h, r: s.r, pickR: Math.max(1.8, s.r * 0.8), reach: s.r + 1.5 });
    }

    function paint(it) {
        const d = it.data;
        const shown = it.shown !== undefined ? it.shown : d.progress;
        it.view.setLevel(d.level || 1);
        it.view.setUpgrading(d.state === 'upgrading');
        it.view.setOutline(outline);
        if (d.state === 'ready' || d.state === 'upgrading') it.view.setStage('ready', false);
        else it.view.setStage(buildStage(shown, d.needed), shown <= 0);
    }

    function add(data) {
        const s = specOf(data.type);
        const view = createBuildingView({ type: data.type, r: s.r, h: s.h });
        view.setOutline(outline);
        place(view, data.x, data.z, data.rot || 0);
        scene.add(view.group);
        const it = { data: { ...data }, view, shown: undefined };
        items.set(data.id, it);
        paint(it);
        refreshTarget(it);
        addBlockers(it);
        return it;
    }

    return {
        setCatalog(list) { specs = list; },
        setOutline(on) { outline = Boolean(on); for (const it of items.values()) it.view.setOutline(outline); },
        get size() { return items.size; },
        get(id) { return items.get(id) ? items.get(id).data : null; },
        list() { return Array.from(items.values(), (it) => it.data); },
        /** Круги габаритов для проверки места: [{ x, z, r, id }]. */
        circles() { return Array.from(items.values(), (it) => ({ id: it.data.id, x: it.data.x, z: it.data.z, r: specOf(it.data.type).r })); },
        /** Полный список с сервера (при входе). */
        set(list) {
            for (const it of items.values()) { it.view.dispose(); removeTarget(targetId(it.data.id)); }
            items.clear();
            for (const b of list || []) add(b);
        },
        /** Добавить или обновить одну постройку (ответ сервера). */
        upsert(data) {
            const it = items.get(data.id);
            if (!it) { add(data); return; }
            const prev = it.data;
            it.data = { ...prev, ...data };
            it.shown = undefined;
            paint(it);
        },
        /** Показать прогресс с ещё не подтверждёнными тапами (только вид). */
        showProgress(id, progress) {
            const it = items.get(id);
            if (!it) return;
            it.shown = progress;
            paint(it);
        },
        remove(id) {
            const it = items.get(id);
            if (!it) return;
            it.view.dispose();
            removeTarget(targetId(id));
            items.delete(id);
        },
        tap(id) { const it = items.get(id); if (it) it.view.tap(); },
        /** После очистки блокеров (смена качества): вернуть непроходимые пятачки и поправить высоту. */
        reblock() {
            for (const it of items.values()) { addBlockers(it); place(it.view, it.data.x, it.data.z, it.data.rot || 0); refreshTarget(it); }
            if (ghost) place(ghost.view, ghost.view.group.position.x, ghost.view.group.position.z, 0);
        },
        /** Призрак при выборе места: type, x, z, rot, kind ('ok' | 'bad'). */
        showGhost(type, x, z, rot, kind) {
            if (!ghost || ghost.type !== type) {
                this.hideGhost();
                const s = specOf(type);
                ghost = { type, view: createBuildingView({ type, r: s.r, h: s.h }) };
                scene.add(ghost.view.group);
            }
            place(ghost.view, x, z, rot);
            ghost.view.setGhost(kind);
        },
        hideGhost() { if (ghost) { ghost.view.dispose(); ghost = null; } },
        get ghostActive() { return Boolean(ghost); },
        update(dt) { for (const it of items.values()) it.view.update(dt); },
        dispose() { this.hideGhost(); for (const it of items.values()) it.view.dispose(); items.clear(); },
    };
}
