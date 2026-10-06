// Экран загрузки: прогресс по шагам (код, мир, сервер).
export function createLoadingScreen() {
    const root = document.getElementById('wfLoading');
    const fill = document.getElementById('wfBarFill');
    const status = document.getElementById('wfLoadStatus');
    const steps = Array.from(document.querySelectorAll('#wfSteps li'));
    const total = steps.length;
    const done = new Set();

    function paint() {
        fill.style.width = `${Math.round((done.size / total) * 100)}%`;
    }

    return {
        // kind: 'done' — шаг готов, 'warn' — шаг не удался, но игра продолжается
        step(id, kind = 'done', note = '') {
            const li = steps.find((el) => el.dataset.step === id);
            if (!li) return;
            li.classList.toggle('is-done', kind === 'done');
            li.classList.toggle('is-warn', kind === 'warn');
            done.add(id);
            if (note) status.textContent = note;
            paint();
        },
        note(text) { status.textContent = text; },
        hide() { root.classList.add('is-hidden'); },
        show() { root.classList.remove('is-hidden'); },
        get progress() { return done.size / total; },
    };
}
