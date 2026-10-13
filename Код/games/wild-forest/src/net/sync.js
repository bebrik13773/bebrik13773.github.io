// Цикл sync (ДЛ-10): раз в next_sync_ms отправляет позицию и накопленные действия, применяет ответ сервера.
// Зависимости (вызов API, таймер, часы) передаются снаружи, поэтому логика проверяется без браузера.
export function createSync({ call, queue, getPos, onState, onCorrect, onResults, onStatus, setTimer = setTimeout, clearTimer = clearTimeout, now = () => Date.now(), isHidden = () => false, baseMs = 8000, maxBackoffMs = 30000 }) {
    let seq = 0;
    let timer = 0;
    let running = false;
    let busy = false;
    let backoff = 0;
    let nextMs = baseMs;
    let offset = 0;        // серверное время минус клиентское, мс (медиана последних ответов)
    const offsets = [];
    let status = 'idle';

    const setStatus = (s) => { if (s !== status) { status = s; if (onStatus) onStatus(s); } };

    function median(arr) { const a = arr.slice().sort((x, y) => x - y); return a[Math.floor(a.length / 2)]; }

    async function tick() {
        if (!running || busy) return;
        busy = true;
        seq += 1;
        const pos = getPos();
        const actions = queue.take();
        const sentAt = now();
        const res = await call('sync', { method: 'POST', body: { seq, client_ts: sentAt, pos, actions, background: isHidden() } });
        busy = false;
        if (!running) return;
        if (res.ok) {
            const d = res.data;
            backoff = 0;
            queue.ack(d.results || []);
            if (typeof d.server_ms === 'number') {
                offsets.push(d.server_ms - Math.round((sentAt + now()) / 2));
                if (offsets.length > 7) offsets.shift();
                offset = median(offsets);
            }
            if (d.state && onState) onState(d.state);
            if (d.correct_pos && onCorrect) onCorrect(d.correct_pos);
            if (d.results && d.results.length && onResults) onResults(d.results);
            nextMs = Math.max(2000, Number(d.next_sync_ms) || baseMs);
            setStatus('online');
        } else if (res.error === 'rate_limited') {
            nextMs = Math.max(3000, Number(res.extra && res.extra.retry_ms) || 3000);
        } else if (res.error === 'unauthorized' || res.error === 'forbidden') {
            running = false;
            setStatus(res.error);
            return;
        } else if (res.error === 'outdated') {
            running = false;
            setStatus('outdated');
            return;
        } else {
            // сеть или сервер: действия остаются в очереди, повторяем с нарастающей паузой
            queue.fail();
            backoff = Math.min(maxBackoffMs, backoff ? backoff * 2 : baseMs);
            nextMs = backoff;
            setStatus('offline');
        }
        schedule();
    }

    function schedule() {
        clearTimer(timer);
        if (!running) return;
        timer = setTimer(tick, nextMs);
    }

    return {
        start(firstDelayMs = 3000) { if (running) return; running = true; nextMs = firstDelayMs; schedule(); },
        stop() { running = false; clearTimer(timer); },
        /** Срочная отправка (например, перед закрытием диалога торга). */
        flush() { clearTimer(timer); return tick(); },
        get status() { return status; },
        get seq() { return seq; },
        get offsetMs() { return offset; },
        get running() { return running; },
        get nextMs() { return nextMs; },
    };
}
