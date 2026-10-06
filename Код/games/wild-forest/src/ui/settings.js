import { QUALITY_ORDER } from '../config.js';

// Минимальное меню настроек каркаса: выбор качества. Полный HUD и меню придут в ДЛ-09.
export function createSettingsUi({ quality, onOpenBeta }) {
    const gear = document.getElementById('wfGear');
    const panel = document.getElementById('wfPanel');
    const group = document.getElementById('wfQuality');
    const betaBtn = document.getElementById('wfBetaOpen');
    const buttons = QUALITY_ORDER.map((id) => group.querySelector(`[data-q="${id}"]`));

    function paint() {
        buttons.forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.q === quality.current ? 'true' : 'false');
        });
    }

    function setOpen(open) {
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        gear.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    gear.addEventListener('click', () => setOpen(!panel.classList.contains('is-open')));
    group.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-q]');
        if (button) quality.set(button.dataset.q);
    });
    betaBtn.addEventListener('click', () => {
        setOpen(false);
        if (onOpenBeta) onOpenBeta();
    });
    quality.subscribe(paint);
    paint();

    return {
        show() { gear.hidden = false; },
        hide() { gear.hidden = true; setOpen(false); },
        close() { setOpen(false); },
    };
}
