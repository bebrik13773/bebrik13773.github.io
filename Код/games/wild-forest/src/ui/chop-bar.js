// Шкала прочности над рубимым деревом (ДЛ-11). HTML-элемент поверх холста: дешевле, чем рисовать в 3D.
// Положение обновляется каждый кадр по проекции верхушки дерева; значения пишутся в DOM только при изменении.
export function createChopBar(host = document.body) {
    const el = document.createElement('div');
    el.className = 'wf-chopbar';
    el.hidden = true;
    el.setAttribute('role', 'progressbar');
    el.setAttribute('aria-label', 'Прочность дерева');
    const fill = document.createElement('div');
    fill.className = 'wf-chopbar-fill';
    el.appendChild(fill);
    host.appendChild(el);
    let lastFrac = -1;
    let lastX = -1;
    let lastY = -1;
    return {
        el,
        /** frac 0..1 — сколько уже срублено; point — экранные координаты или null (верхушка за кадром). */
        set(frac, point) {
            if (!point) { el.hidden = true; return; }
            el.hidden = false;
            const f = Math.round(Math.max(0, Math.min(1, frac)) * 100) / 100;
            if (f !== lastFrac) {
                lastFrac = f;
                fill.style.transform = `scaleX(${f})`;
                el.setAttribute('aria-valuenow', String(Math.round(f * 100)));
            }
            const x = Math.round(point.x);
            const y = Math.round(point.y);
            if (x !== lastX || y !== lastY) { lastX = x; lastY = y; el.style.transform = `translate(${x}px, ${y}px) translate(-50%, -100%)`; }
        },
        hide() { el.hidden = true; lastFrac = -1; },
        dispose() { el.remove(); },
    };
}
