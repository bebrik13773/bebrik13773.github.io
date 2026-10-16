// Связка энергии, сна и еды (ДЛ-12): состояние сервера → сон бобра, действие eat → очередь, разбор ответа.
// Все зависимости передаются снаружи (мир, очередь, sync, уведомления), поэтому логика проверяется в node без браузера.
import { ENERGY } from '../shared/formulas.js';

const MIN_FLUSH_GAP_MS = 3200; // сервер принимает sync не чаще раза в 3 секунды

export function createEnergyFlow({ world, getQueue, getSync, notify, text, onEatLocal = () => {}, now = () => Date.now(), setTimer = setTimeout, clearTimer = clearTimeout }) {
    let sleeping = false;
    let lastEatAt = -1e12;
    let lastFlush = 0;
    let flushTimer = 0;
    let animTimer = 0;

    function requestFlush() {
        const sync = getSync();
        if (!sync || !sync.running) return;
        clearTimer(flushTimer);
        const wait = Math.max(0, lastFlush + MIN_FLUSH_GAP_MS - now());
        if (wait === 0) { lastFlush = now(); sync.flush(); }
        else flushTimer = setTimer(() => { lastFlush = now(); sync.flush(); }, wait);
    }

    function releaseAnim() {
        clearTimer(animTimer);
        if (!sleeping) world.releaseBeaver();
    }

    return {
        get sleeping() { return sleeping; },
        /** Съесть рыбу из рюкзака. false — отказано на клиенте (нет связи или рано после прошлого приёма). */
        eat(item) {
            const queue = getQueue();
            if (!queue) { notify({ text: text.offline }); return false; }
            if (now() - lastEatAt < ENERGY.eatPauseS * 1000) { notify({ text: text.eat_wait }); return false; }
            lastEatAt = now();
            queue.push('eat', { item });
            onEatLocal(item);
            if (!sleeping) {
                world.playBeaver('eat');
                clearTimer(animTimer);
                animTimer = setTimer(releaseAnim, ENERGY.eatAnimS * 1000);
            }
            requestFlush();
            return true;
        },
        /** Состояние сервера (приходит в каждом sync): включает и выключает сон. */
        onState(st) {
            if (!st || typeof st.sleeping !== 'boolean' || st.sleeping === sleeping) return;
            sleeping = st.sleeping;
            world.setSleeping(sleeping);
            notify({ text: sleeping ? text.asleep : text.wake });
        },
        /** Результаты sync по действиям eat. */
        onResults(results) {
            for (const r of results) {
                if (r.dup || r.type !== 'eat') continue;
                if (r.ok) { notify({ text: text.ate(Math.round(r.gain || 0)) }); continue; }
                clearTimer(animTimer);
                if (!sleeping) world.releaseBeaver();
                if (r.error === 'no_items') notify({ text: text.no_items });
                else if (r.error === 'too_fast') notify({ text: text.eat_wait });
                else if (r.error === 'bad_state' && r.full) notify({ text: text.not_hungry });
                else notify({ text: text.failed });
            }
        },
        dispose() { clearTimer(flushTimer); clearTimer(animTimer); },
    };
}
