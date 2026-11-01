// Наставник Седой Дуб (ДЛ-15): ведёт нового игрока от первой ходьбы до первой продажи.
// Шаги идут по порядку: реплики наставника (компонент диалога из ДЛ-09), затем, если шаг требует действия, подсказка «тапни здесь»
// со стрелкой над нужной целью и ожидание события. Пройденный шаг сохраняется на сервере действием tutorial.
// Все зависимости передаются снаружи, поэтому логика проверяется в Node без браузера.

import { createTutorial, eventFromResult, nearestPoint, clampToScreen, MOVE_DIST, TUTORIAL_DONE } from './tutorial-logic.js';

export function createTutorialFlow({ world, dialog, hint, text, getQueue, getPack = () => ({}), getBox = () => ({}), getSpots = () => ({}), startStep = 0, size = () => ({ w: window.innerWidth, h: window.innerHeight }), now = () => Date.now() }) {
    const logic = createTutorial(startStep);
    let token = 0;       // номер запуска: смена отменяет прежний прогон
    let waiter = null;   // resolve ожидания события
    let origin = null;   // откуда бобёр пошёл (шаг «ходьба»)
    let target = null;   // текущая цель подсказки { x, y, z }
    let targetAt = 0;

    const queue = () => getQueue();
    function persist(fields) {
        const q = queue();
        if (q) q.push('tutorial', fields);
    }

    function finishWait(ok) {
        if (waiter) { const w = waiter; waiter = null; w(ok); }
    }

    function hintClear() { target = null; hint.hide(); }

    function pickTarget() {
        const kind = logic.target;
        const pos = world.heroPos;
        let p = null;
        if (kind === 'tree') {
            const trees = [];
            world.chunks.forEachInRange(pos.x, pos.z, 45, (o) => { if (o.kind === 1 || o.kind === 'tree') trees.push(o); });
            const o = nearestPoint(trees, pos.x, pos.z);
            if (o) p = { x: o.x, z: o.z, h: (o.h || 6) + 0.8 };
        } else if (kind === 'drop') {
            const o = nearestPoint(world.drops ? world.drops.list() : [], pos.x, pos.z);
            if (o) p = { x: o.x, z: o.z, h: 1.4 };
        } else if (kind === 'crate' || kind === 'buyer') {
            const s = getSpots()[kind];
            if (s) p = { x: s.x, z: s.z, h: 2.2 };
        }
        target = p ? { x: p.x, z: p.z, y: world.groundY(p.x, p.z) + p.h } : null;
        targetAt = now();
    }

    async function say(line, choices) {
        return dialog.say({ speaker: text.mentor, portrait: text.portrait, text: line, choices: choices || [] });
    }

    function alreadyDone() {
        const id = logic.id;
        if (id === 'pickup') return (getPack().log || 0) > 0;
        if (id === 'crate') return (getBox().log || 0) > 0;
        return false;
    }

    async function run(myToken) {
        while (myToken === token && !logic.done) {
            const id = logic.id;
            const lines = text.steps[id] || [];
            for (let i = 0; i < lines.length; i += 1) {
                const choices = id === 'hello' && i === 0 ? [{ id: 'go', label: text.yes }, { id: 'skip', label: text.no }] : [];
                const res = await say(lines[i], choices);
                if (myToken !== token || res.id === 'cancel') return;
                if (res.id === 'skip') { skip(); return; }
            }
            if (logic.wait && !alreadyDone()) {
                origin = { ...world.heroPos };
                pickTarget();
                hint.show(text.hints[id]);
                const ok = await new Promise((resolve) => { waiter = resolve; });
                hintClear();
                if (myToken !== token || !ok) return;
            }
            persist({ step: logic.complete() });
        }
        if (myToken === token && logic.done) hintClear();
    }

    function skip() {
        token += 1;
        finishWait(false);
        dialog.cancelAll();
        hintClear();
        persist({ step: logic.skip() });
    }

    hint.onSkip(() => { skip(); });

    function event(name) {
        if (logic.accepts(name)) finishWait(true);
    }

    return {
        get step() { return logic.step; },
        get done() { return logic.done; },
        get running() { return !logic.done && token > 0; },
        get waiting() { return Boolean(waiter); },
        get target() { return target; },
        start() { if (logic.done) return false; token += 1; run(token); return true; },
        /** «Повторить обучение» из настроек: с самого начала, сервер тоже сбрасывает шаг. */
        restart() {
            token += 1;
            finishWait(false);
            dialog.cancelAll();
            hintClear();
            logic.reset();
            persist({ reset: true });
            token += 1;
            run(token);
        },
        skip,
        onResults(results) { for (const r of results) { const e = eventFromResult(r); if (e) event(e); } },
        event,
        /** Каждый кадр: шаг «ходьба» и стрелка подсказки. */
        update() {
            if (!waiter) return;
            if (logic.wait === 'moved' && origin) {
                const pos = world.heroPos;
                if (Math.hypot(pos.x - origin.x, pos.z - origin.z) >= MOVE_DIST) { event('moved'); return; }
            }
            if (alreadyDone()) { finishWait(true); return; }
            if (!target || now() - targetAt > 600) pickTarget();
            if (!target) { hint.point(null); return; }
            const sp = world.projectToScreen(target.x, target.y, target.z);
            const s = size();
            // Цель за спиной камеры: стрелка у нижнего края (подсказка «оглянись»)
            hint.point(sp ? clampToScreen(sp.sx, sp.sy, s.w, s.h) : { x: s.w / 2, y: s.h - 70, off: true });
        },
        TUTORIAL_DONE,
    };
}
