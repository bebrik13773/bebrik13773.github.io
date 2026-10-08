// Отладочная панель (?debug=1): FPS, вызовы отрисовки, треугольники, память.
export function isDebugMode() {
    try {
        return new URLSearchParams(window.location.search).get('debug') === '1';
    } catch (e) {
        return false;
    }
}

export function createDebugPanel() {
    const el = document.getElementById('wfDebug');
    el.hidden = false;
    let lastPaint = 0;

    function heapMb() {
        const mem = performance && performance.memory;
        return mem ? `${Math.round(mem.usedJSHeapSize / 1048576)} МБ` : 'н/д';
    }

    return {
        update(now, { fps, info, quality, state, trees, chunks }) {
            if (now - lastPaint < 500) return;
            lastPaint = now;
            const lines = [
                `FPS ${fps.toFixed(0)}  [${quality}]  ${state}`,
                info ? `calls ${info.calls}  tris ${info.triangles}` : 'нет рендерера',
                info ? `geo ${info.geometries}  tex ${info.textures}` : '',
                info ? `buf ${info.width}x${info.height} dpr ${info.pixelRatio.toFixed(2)}` : '',
                `trees ${trees}  heap ${heapMb()}`,
                chunks ? `chunks ${chunks.loaded}  queue ${chunks.pending}  stumps ${chunks.stumpCount}` : '',
            ];
            el.textContent = lines.filter(Boolean).join('\n');
        },
    };
}
