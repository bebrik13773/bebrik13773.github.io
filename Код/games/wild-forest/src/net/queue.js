// Очередь действий клиента (ДЛ-10). Каждое действие получает номер a (растёт на 1); сервер помнит последний обработанный,
// поэтому повторная отправка безопасна. Чистая логика без сети и DOM, проверяется в node --test.
export function createActionQueue(startA = 0, maxPerPacket = 60) {
    let counter = Math.max(0, startA | 0);
    let pending = [];   // ещё не отправлены
    let inflight = [];  // отправлены, ответа нет

    return {
        push(type, payload = {}) {
            counter += 1;
            pending.push({ a: counter, type, ...payload });
            return counter;
        },
        /** Забирает пачку для отправки: сначала неподтверждённые (повтор), затем новые. */
        take() {
            const batch = inflight.concat(pending).slice(0, maxPerPacket);
            const ids = new Set(batch.map((x) => x.a));
            inflight = batch;
            pending = pending.filter((x) => !ids.has(x.a));
            return batch.map((x) => ({ ...x }));
        },
        /** Ответ получен: действия из results больше не нужны (в том числе отклонённые: повтор не поможет). */
        ack(results = []) {
            const done = new Set(results.map((r) => r.a));
            inflight = inflight.filter((x) => !done.has(x.a));
        },
        /** Пакет не дошёл: действия остаются в inflight и уйдут снова. */
        fail() { /* inflight сохраняется как есть */ },
        reset(startAfter) { counter = Math.max(0, startAfter | 0); pending = []; inflight = []; },
        get size() { return pending.length + inflight.length; },
        get lastA() { return counter; },
    };
}
