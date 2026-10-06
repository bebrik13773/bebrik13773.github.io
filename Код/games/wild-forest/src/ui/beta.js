import { BETA, BETA_NOTICE_TTL_MS } from '../config.js';

const ACK_KEY = 'wf.betaAckAt';

// Окно «Это бета-версия»: показывается раз в сутки и по кнопке в настройках.
export function createBetaNotice() {
    const modal = document.getElementById('wfBetaModal');
    const ackBtn = document.getElementById('wfBetaAck');
    const badge = document.getElementById('wfBadge');
    const openBtn = document.getElementById('wfBetaOpen');

    function setOpen(open) {
        modal.classList.toggle('is-open', open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) ackBtn.focus();
    }

    function isFresh() {
        try {
            const at = Number(localStorage.getItem(ACK_KEY) || 0);
            return at > 0 && Date.now() - at < BETA_NOTICE_TTL_MS;
        } catch (e) {
            return false;
        }
    }

    ackBtn.addEventListener('click', () => {
        try {
            localStorage.setItem(ACK_KEY, String(Date.now()));
        } catch (e) { /* без хранилища окно просто покажется снова */ }
        setOpen(false);
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) setOpen(false);
    });

    if (!BETA) {
        badge.hidden = true;
        openBtn.hidden = true;
    }

    return {
        open: () => setOpen(true),
        // Показать при входе, если давно не видели.
        showIfDue() {
            if (BETA && !isFresh()) setOpen(true);
        },
    };
}
