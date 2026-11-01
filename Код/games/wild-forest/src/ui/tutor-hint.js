// Подсказка обучения (ДЛ-15): карточка сверху с текстом и кнопкой «Пропустить» и стрелка «тапни здесь» над целью.
// Тапы сквозь стрелку и карточку проходят в мир (pointer-events отключены везде, кроме кнопки).
export function createTutorHint() {
    const $ = (id) => document.getElementById(id);
    const root = $('wfTutor');
    const textEl = $('wfTutorText');
    const arrow = $('wfTutorArrow');
    const skipBtn = $('wfTutorSkip');
    let onSkip = () => {};
    skipBtn.addEventListener('click', (event) => { event.stopPropagation(); onSkip(); });
    return {
        show(text) { textEl.textContent = text; root.hidden = false; arrow.hidden = true; },
        hide() { root.hidden = true; arrow.hidden = true; },
        /** point({x, y, off}) ставит стрелку, null прячет её. */
        point(p) {
            if (!p) { arrow.hidden = true; return; }
            arrow.hidden = false;
            arrow.style.transform = `translate(${Math.round(p.x)}px, ${Math.round(p.y)}px) translate(-50%, -100%)`;
            arrow.dataset.off = p.off ? '1' : '0';
        },
        onSkip(fn) { onSkip = fn; },
        get visible() { return !root.hidden; },
    };
}
