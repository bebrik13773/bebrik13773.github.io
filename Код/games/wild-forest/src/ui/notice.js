// Небольшое уведомление внизу экрана с кнопками (подсказка снизить качество, ошибки).
export function createNotice() {
    const el = document.getElementById('wfNotice');
    let timer = 0;

    function close() {
        el.classList.remove('is-open', 'is-danger');
        if (timer) clearTimeout(timer);
        timer = 0;
    }

    return {
        show({ text, danger = false, actions = [], ttlMs = 0 }) {
            close();
            el.textContent = '';
            const p = document.createElement('p');
            p.textContent = text;
            el.appendChild(p);
            if (actions.length) {
                const row = document.createElement('div');
                row.className = 'wf-actions';
                actions.forEach((action) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = action.ghost ? 'wf-btn is-ghost' : 'wf-btn';
                    button.textContent = action.label;
                    button.addEventListener('click', () => {
                        close();
                        if (action.onClick) action.onClick();
                    });
                    row.appendChild(button);
                });
                el.appendChild(row);
            }
            el.classList.toggle('is-danger', danger);
            el.classList.add('is-open');
            if (ttlMs > 0) timer = setTimeout(close, ttlMs);
        },
        close,
    };
}
