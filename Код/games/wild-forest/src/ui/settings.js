import { QUALITY_ORDER, OLD_GAME_URL } from '../config.js';
import { S } from './strings.ru.js';
import { getPref, setPref } from './prefs.js';

const VOLUMES = [
    { id: 'wfVolSfx', key: 'wf.vol.sfx', kind: 'sfx' },
    { id: 'wfVolMusic', key: 'wf.vol.music', kind: 'music' },
];

// Меню настроек (ДЛ-09): качество графики, громкость звуков и музыки, повтор обучения, выход, окно беты.
// Громкость сохраняется в localStorage и сообщается через onVolume (менеджер звука придёт в ДЛ-31).
export function createSettingsUi({ quality, notice, onOpenBeta, onVolume = () => {}, onAutoPickup = () => {}, onRepeatTutorial = () => {} }) {
    const gear = document.getElementById('wfGear');
    const panel = document.getElementById('wfPanel');
    const group = document.getElementById('wfQuality');
    const betaBtn = document.getElementById('wfBetaOpen');
    const tutorialBtn = document.getElementById('wfTutorialRepeat');
    const exitBtn = document.getElementById('wfExit');
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

    VOLUMES.forEach(({ id, key, kind }) => {
        const input = document.getElementById(id);
        const out = document.getElementById(`${id}Out`);
        const show = (v) => {
            input.value = String(Math.round(v * 100));
            out.textContent = `${Math.round(v * 100)}%`;
            input.setAttribute('aria-valuetext', `${Math.round(v * 100)}%`);
        };
        show(getPref(key));
        input.addEventListener('input', () => {
            const v = setPref(key, Number(input.value) / 100);
            show(v);
            onVolume(kind, v);
        });
    });

    const auto = document.getElementById('wfAutoPickup');
    if (auto) {
        auto.checked = getPref('wf.autopickup') >= 0.5;
        auto.addEventListener('change', () => { setPref('wf.autopickup', auto.checked ? 1 : 0); onAutoPickup(auto.checked); });
    }

    tutorialBtn.addEventListener('click', () => {
        setOpen(false);
        onRepeatTutorial();
    });
    exitBtn.addEventListener('click', () => {
        setOpen(false);
        notice.show({
            text: S.settings.exitConfirm,
            actions: [
                { label: S.settings.exitYes, onClick: () => { window.location.href = OLD_GAME_URL; } },
                { label: S.settings.exitNo, ghost: true },
            ],
        });
    });

    quality.subscribe(paint);
    paint();

    return {
        show() { gear.hidden = false; },
        hide() { gear.hidden = true; setOpen(false); },
        close() { setOpen(false); },
        get isOpen() { return panel.classList.contains('is-open'); },
        autoPickup: () => getPref('wf.autopickup') >= 0.5,
        volumes: () => ({ sfx: getPref('wf.vol.sfx'), music: getPref('wf.vol.music') }),
    };
}
