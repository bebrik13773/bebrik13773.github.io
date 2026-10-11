// Журнал позиций героя (ДЛ-08): раз в секунду опорная точка. При синхронизации с сервером (ДЛ-10) точки уходят пакетом,
// а сервер проверяет скорость по правилу «не больше vMax × dt × 1.35 + 3 м» (shared/formulas.js, checkTrack).
export function createPositionTrack({ intervalMs = 1000, max = 90 } = {}) {
    let points = [];
    let lastMs = -Infinity;
    return {
        /** Запоминает точку не чаще раза в intervalMs. Время nowMs монотонное (performance.now). */
        sample(nowMs, x, z) {
            if (nowMs - lastMs < intervalMs) return false;
            lastMs = nowMs;
            points.push({ t: nowMs / 1000, x: Math.round(x * 100) / 100, z: Math.round(z * 100) / 100 });
            if (points.length > max) points = points.slice(points.length - max);
            return true;
        },
        /** Отдаёт накопленные точки; последняя остаётся опорной для следующего пакета (цепочка непрерывна). */
        drain() {
            const out = points.slice();
            points = points.length ? [points[points.length - 1]] : [];
            return out;
        },
        peek: () => points.slice(),
        get size() { return points.length; },
    };
}
